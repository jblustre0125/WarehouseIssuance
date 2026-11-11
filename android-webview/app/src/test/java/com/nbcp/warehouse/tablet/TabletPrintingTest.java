package com.nbcp.warehouse.tablet;

import org.junit.Test;
import java.nio.charset.StandardCharsets;
import java.util.Arrays;
import java.util.Base64;
import java.util.Collections;
import static org.junit.Assert.*;

public class TabletPrintingTest {
    @Test public void serverAddressRestrictsOriginAndAppPath() {
        ServerAddress address = new ServerAddress("https://warehouse.example:8443/WarehouseIssuance");
        assertEquals("https://warehouse.example:8443/WarehouseIssuance/", address.baseUrl);
        assertEquals("https://warehouse.example:8443", address.origin);
        assertTrue(address.allows(address.baseUrl + "pages/issuer/issuer.php?tab=print"));
        assertFalse(address.allows("https://warehouse.example:8443/other/"));
        assertFalse(address.allows("https://warehouse.example:8443/WarehouseIssuance/../other/"));
        assertFalse(address.allows("https://warehouse.example:8443/WarehouseIssuance/%2e%2e/other/"));
        assertFalse(address.allows("https://warehouse.example:8443/WarehouseIssuance.evil/"));
        assertFalse(address.allows("https://warehouse.example.evil:8443/WarehouseIssuance/"));
        assertFalse(address.allows("https://warehouse.example/WarehouseIssuance/"));
        assertFalse(address.allows("http://warehouse.example:8443/WarehouseIssuance/"));
        assertFalse(address.allows("file:///WarehouseIssuance/"));
    }

    @Test public void defaultHttpsPortMatches() {
        assertTrue(new ServerAddress("https://warehouse.example/").allows("https://warehouse.example:443/"));
    }

    @Test public void unsafeOrLocalServerAddressesAreRejected() {
        for (String url : Arrays.asList("", "warehouse.example", "javascript:alert(1)", "file:///sdcard/", "http://localhost/",
                "http://127.0.0.1/", "http://[::1]/", "https://name:password@warehouse.example/", "http://warehouse.example:70000/",
                "http://warehouse.example/path?query=1", "http://warehouse.example/path#fragment")) {
            assertThrows(url, IllegalArgumentException.class, () -> new ServerAddress(url));
        }
    }

    @Test public void labelsPreserveUtf8BytesAndOrder() {
        byte[] label = "^XA^CI28^FD材料^FS^XZ".getBytes(StandardCharsets.UTF_8);
        LabelBatch batch = new LabelBatch(Arrays.asList(Base64.getEncoder().encodeToString(label), "XlhBXlha"), 5000);
        assertArrayEquals(label, batch.labels.get(0));
        assertEquals("^XA^XZ", new String(batch.labels.get(1), StandardCharsets.UTF_8));
        assertEquals(5000, batch.delayMs);
    }

    @Test public void invalidAndOversizedBatchesAreRejectedBeforeConnection() {
        assertThrows(IllegalArgumentException.class, () -> new LabelBatch(Collections.emptyList(), 0));
        assertThrows(IllegalArgumentException.class, () -> new LabelBatch(Collections.nCopies(101, "XlhBXlha"), 0));
        assertThrows(IllegalArgumentException.class, () -> new LabelBatch(Collections.singletonList("!invalid!"), 0));
        assertThrows(IllegalArgumentException.class, () -> new LabelBatch(Collections.singletonList(""), 0));
        assertThrows(IllegalArgumentException.class, () -> new LabelBatch(Collections.singletonList("XlhBXlha"), -1));
        assertThrows(IllegalArgumentException.class, () -> new LabelBatch(Collections.singletonList("XlhBXlha"), 30001));
        String oversizedLabel = Base64.getEncoder().encodeToString(new byte[65537]);
        assertThrows(IllegalArgumentException.class, () -> new LabelBatch(Collections.singletonList(oversizedLabel), 0));
        String maximumLabel = Base64.getEncoder().encodeToString(new byte[65536]);
        assertThrows(IllegalArgumentException.class, () -> new LabelBatch(Collections.nCopies(33, maximumLabel), 0));
    }
}
