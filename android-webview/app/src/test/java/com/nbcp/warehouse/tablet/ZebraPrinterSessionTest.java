package com.nbcp.warehouse.tablet;

import org.junit.Test;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.nio.charset.StandardCharsets;
import java.util.ArrayDeque;
import java.util.Queue;
import static org.junit.Assert.*;

public class ZebraPrinterSessionTest {
    private static final byte[] LABEL = "^XA^FO10,10^FDTEST^FS^PQ1^XZ\r\n".getBytes(StandardCharsets.UTF_8);
    private static final class Printer extends OutputStream implements ZebraPrinterSession.Time {
        final Queue<Integer> replies = new ArrayDeque<>();
        final ByteArrayOutputStream command = new ByteArrayOutputStream();
        final InputStream input = new InputStream() {
            public int available() { return replies.size(); }
            public int read() { return replies.isEmpty() ? -1 : replies.remove(); }
        };
        long time;
        int labelsWritten;
        int count = 100;
        int increment = 1;
        int paper, paused, head;
        String model = "QLn320";
        String language = "hybrid_xml_zpl";
        boolean silent, counterUnsupported, malformed;
        public long now() { return time; }
        public void pause(long millis) { time += millis; }
        public void write(int value) { command.write(value); }
        public void flush() {
            String sent = new String(command.toByteArray(), StandardCharsets.US_ASCII);
            command.reset();
            if (sent.startsWith("^XA")) { labelsWritten++; count += increment; return; }
            if (silent) return;
            String reply;
            if (sent.contains("device.product_name")) reply = "\"" + model + "\"\r\n";
            else if (sent.contains("device.languages")) reply = "\"" + language + "\"\r\n";
            else if (sent.contains("odometer.total_label_count")) reply = "\"" + (counterUnsupported ? "?" : count) + "\"\r\n";
            else if (sent.startsWith("~HS")) reply = malformed ? "\u0002bad\u0003\u0002bad\u0003\u0002bad\u0003" :
                    "\u0002000," + paper + "," + paused + ",0609,000,0,0,0,000,0,0,0\u0003\r\n"
                    + "\u0002000,0," + head + ",0,0,0,0,0,00000000,1,000\u0003\r\n\u0002000,0\u0003\r\n";
            else throw new AssertionError("Unexpected command: " + sent);
            for (byte value : reply.getBytes(StandardCharsets.US_ASCII)) replies.add(value & 255);
        }
        ZebraPrinterSession session() { return new ZebraPrinterSession(input, this, this); }
    }

    @Test public void confirmsActualCounterAdvance() throws Exception {
        Printer device = new Printer();
        ZebraPrinterSession session = device.session();
        assertEquals("QLn320", session.verifyReady());
        session.printAndConfirm(LABEL);
        assertEquals(1, device.labelsWritten);
        assertEquals(101, device.count);
    }

    @Test public void successfulWriteWithoutPrintIsFailureAndNeverRetries() throws Exception {
        Printer device = new Printer();
        device.increment = 0;
        ZebraPrinterSession session = device.session();
        session.verifyReady();
        IOException failure = assertThrows(IOException.class, () -> session.printAndConfirm(LABEL));
        assertTrue(failure.getMessage().contains("did not confirm"));
        assertEquals(1, device.labelsWritten);
    }

    @Test public void rejectsScannerOrWrongPrinterBeforeLabelData() {
        Printer device = new Printer();
        device.model = "Scanner_877B";
        assertThrows(IOException.class, () -> device.session().verifyReady());
        assertEquals(0, device.labelsWritten);
    }

    @Test public void rejectsNonZplLanguageWithoutChangingPrinterSettings() {
        Printer device = new Printer();
        device.language = "line_print";
        IOException error = assertThrows(IOException.class, () -> device.session().verifyReady());
        assertTrue(error.getMessage().contains("Enable ZPL"));
        assertEquals(0, device.labelsWritten);
    }

    @Test public void reportsPaperPauseAndOpenHeadBeforePrinting() {
        for (int fault = 0; fault < 3; fault++) {
            Printer device = new Printer();
            if (fault == 0) device.paper = 1;
            if (fault == 1) device.paused = 1;
            if (fault == 2) device.head = 1;
            assertThrows(IOException.class, () -> device.session().verifyReady());
            assertEquals(0, device.labelsWritten);
        }
    }

    @Test public void malformedStatusAndUnsupportedCounterCannotSucceed() {
        Printer malformed = new Printer();
        malformed.malformed = true;
        assertThrows(IOException.class, () -> malformed.session().verifyReady());
        Printer noCounter = new Printer();
        noCounter.counterUnsupported = true;
        assertThrows(IOException.class, () -> noCounter.session().verifyReady());
        assertEquals(0, malformed.labelsWritten + noCounter.labelsWritten);
    }

    @Test public void noResponseTimesOutWithoutSendingLabel() {
        Printer device = new Printer();
        device.silent = true;
        assertThrows(IOException.class, () -> device.session().verifyReady());
        assertEquals(0, device.labelsWritten);
        assertTrue(device.time >= 5000);
    }

    @Test public void extraCounterAdvanceCannotConfirmThisJob() throws Exception {
        Printer device = new Printer();
        device.increment = 2;
        ZebraPrinterSession session = device.session();
        session.verifyReady();
        assertThrows(IOException.class, () -> session.printAndConfirm(LABEL));
        assertEquals(1, device.labelsWritten);
    }

    @Test public void streamModeAndPrintedLabelWaitingForRemovalAreSupported() throws Exception {
        ZebraPrinterSession.Status state = ZebraPrinterSession.Status.parse(
                "\u0002000,0,0,0609,000,0,0,0,000,0,0,0\u0003\r\n"
                + "\u0002000,0,0,0,0,S,0,1,00000000,1,000\u0003\r\n\u0002000,0\u0003\r\n");
        state.requireReady();
        assertFalse(state.idle());
        assertTrue(state.finished());
    }
}
