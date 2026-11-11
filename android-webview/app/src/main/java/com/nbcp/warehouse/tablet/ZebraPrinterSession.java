package com.nbcp.warehouse.tablet;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.function.Predicate;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** Read printer identity and status; a successful write alone is never a print. */
public final class ZebraPrinterSession {
    interface Time {
        long now();
        void pause(long millis) throws InterruptedException;
    }
    private final InputStream input;
    private final OutputStream output;
    private final Time time;
    private static final Pattern SGD = Pattern.compile("^\\s*\"([^\"]*)\"\\s*$");
    private static final Pattern FRAME = Pattern.compile("\u0002([^\u0003]*)\u0003");

    public ZebraPrinterSession(InputStream input, OutputStream output) {
        this(input, output, new Time() {
            public long now() { return System.nanoTime() / 1000000; }
            public void pause(long millis) throws InterruptedException { Thread.sleep(millis); }
        });
    }

    ZebraPrinterSession(InputStream input, OutputStream output, Time time) {
        this.input = input;
        this.output = output;
        this.time = time;
    }

    private void write(byte[] bytes) throws IOException {
        for (int offset = 0; offset < bytes.length; offset += 1024) {
            output.write(bytes, offset, Math.min(1024, bytes.length - offset));
        }
        output.flush();
    }

    private String response(String command, Predicate<String> complete) throws IOException, InterruptedException {
        write(command.getBytes(StandardCharsets.US_ASCII));
        ByteArrayOutputStream received = new ByteArrayOutputStream();
        long deadline = time.now() + 5000;
        while (time.now() < deadline) {
            int available = input.available();
            if (available > 0) {
                byte[] chunk = new byte[Math.min(available, 1024)];
                int read = input.read(chunk);
                if (read < 0) throw new IOException("Bluetooth printer disconnected.");
                received.write(chunk, 0, read);
                if (received.size() > 8192) throw new IOException("Unexpected printer response.");
                String text = new String(received.toByteArray(), StandardCharsets.US_ASCII);
                if (complete.test(text)) return text;
            } else time.pause(20);
        }
        throw new IOException("No valid reply from the printer. Check that the selected device is the QLn320, powered on, and in range.");
    }

    String setting(String name) throws IOException, InterruptedException {
        String reply = response("! U1 getvar \"" + name + "\"\r\n", value -> SGD.matcher(value).matches());
        Matcher parsed = SGD.matcher(reply);
        if (!parsed.matches()) throw new IOException("Invalid printer setting response.");
        return parsed.group(1);
    }

    long counter() throws IOException, InterruptedException {
        String value = setting("odometer.total_label_count");
        if (!value.matches("[0-9]{1,18}")) throw new IOException("The printer did not return its printed-label counter. Printing cannot be confirmed.");
        return Long.parseLong(value);
    }

    Status status() throws IOException, InterruptedException {
        return Status.parse(response("~HS\r\n", value -> frames(value).size() == 3));
    }

    public String verifyReady() throws IOException, InterruptedException {
        String model = setting("device.product_name");
        if (!model.toLowerCase(Locale.ROOT).replace(" ", "").contains("qln320")) {
            throw new IOException("The selected device identifies as '" + model + "', not a QLn320. Select the printer in the floating menu.");
        }
        String language = setting("device.languages");
        if (!language.toLowerCase(Locale.ROOT).contains("zpl")) {
            throw new IOException("The QLn320 language is '" + language + "'. Enable ZPL using Zebra Setup Utilities before printing these labels.");
        }
        Status state = status();
        state.requireReady();
        if (!state.idle()) throw new IOException("The printer already has a pending label. Clear or finish that job before printing again.");
        counter(); // Fail before sending a label if completion cannot be verified.
        return model;
    }

    public void printAndConfirm(byte[] label) throws IOException, InterruptedException {
        Status before = status();
        before.requireReady();
        if (!before.idle()) throw new IOException("The printer is still processing a previous label.");
        long baseline = counter();
        write(label);
        long deadline = time.now() + 20000;
        while (time.now() < deadline) {
            Status state = status();
            state.requireReady();
            long current = counter();
            if (current == baseline + 1 && state.finished()) return;
            if (current < baseline || current > baseline + 1) {
                throw new IOException("The printer counter changed unexpectedly. Check printed labels before retrying.");
            }
            time.pause(200);
        }
        throw new IOException("Label data was sent, but the printer did not confirm a printed label. Check paper, cover, pause, and label output before retrying.");
    }

    private static List<String> frames(String value) {
        List<String> result = new ArrayList<>();
        Matcher matcher = FRAME.matcher(value);
        while (matcher.find()) result.add(matcher.group(1));
        return result;
    }

    static final class Status {
        private final int[] first;
        private final int[] second;
        private Status(int[] first, int[] second) { this.first = first; this.second = second; }
        static Status parse(String value) throws IOException {
            List<String> records = frames(value);
            if (records.size() != 3) throw new IOException("Incomplete Zebra status reply.");
            return new Status(numbers(records.get(0), 12), numbers(records.get(1), 11));
        }
        private static int[] numbers(String record, int expected) throws IOException {
            String[] fields = record.split(",", -1);
            if (fields.length != expected) throw new IOException("Invalid Zebra status fields.");
            int[] result = new int[expected];
            for (int i = 0; i < expected; i++) {
                // Zebra also reports K (kiosk) and S (stream) print modes.
                if (expected == 11 && i == 5 && fields[i].trim().matches("[KSks]")) { result[i] = -1; continue; }
                try { result[i] = Integer.parseInt(fields[i].trim()); }
                catch (NumberFormatException error) { throw new IOException("Invalid Zebra status value."); }
            }
            return result;
        }
        void requireReady() throws IOException {
            if (first[1] != 0) throw new IOException("The printer is out of label paper.");
            if (first[2] != 0) throw new IOException("The printer is paused. Resume it on the printer.");
            if (second[2] != 0) throw new IOException("The printer cover / printhead is open.");
            if (second[3] != 0) throw new IOException("The printer reports ribbon out.");
            if (first[5] != 0) throw new IOException("The printer receive buffer is full.");
            if (first[6] != 0) throw new IOException("The printer is in communications diagnostic mode.");
            if (first[9] != 0) throw new IOException("The printer reports a memory error.");
            if (first[10] != 0 || first[11] != 0) throw new IOException("The printhead temperature is outside its operating range.");
        }
        boolean idle() { return first[4] == 0 && first[7] == 0 && second[7] == 0 && second[8] == 0; }
        boolean finished() { return first[4] == 0 && first[7] == 0 && second[8] == 0; }
    }
}
