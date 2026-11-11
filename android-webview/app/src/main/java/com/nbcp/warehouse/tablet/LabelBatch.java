package com.nbcp.warehouse.tablet;

import java.util.ArrayList;
import java.util.Base64;
import java.util.List;

public final class LabelBatch {
    public final List<byte[]> labels = new ArrayList<>();
    public final int delayMs;

    public LabelBatch(List<String> encodedLabels, int delayMs) {
        if (encodedLabels.isEmpty() || encodedLabels.size() > 100 || delayMs < 0 || delayMs > 30000) {
            throw new IllegalArgumentException("Print 1-100 labels with a delay of 0-30 seconds.");
        }
        this.delayMs = delayMs;
        int total = 0;
        for (String encoded : encodedLabels) {
            if (encoded.length() > 87384) throw new IllegalArgumentException("A label is too large.");
            byte[] bytes = Base64.getDecoder().decode(encoded);
            total += bytes.length;
            if (bytes.length == 0 || bytes.length > 65536 || total > 2097152) {
                throw new IllegalArgumentException("The print batch is empty or too large.");
            }
            labels.add(bytes);
        }
    }
}
