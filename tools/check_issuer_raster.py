"""Independently check generated Zebra graphics against the PNG preview.

Run after tools/preview_issuer_tag.php. Requires Pillow; optionally zxing-cpp
for a scanner check (neither is a production dependency).
"""
from pathlib import Path
import re
import sys
from PIL import Image, ImageChops, ImageOps


def decode_graphic(data, row_bytes, total):
    """Decode Zebra ASCII graphic repeats without using the PHP encoder."""
    rows, row, count = [], "", 0
    width = row_bytes * 2
    for char in data:
        if "G" <= char <= "Y":
            count += ord(char) - ord("G") + 1
        elif "g" <= char <= "z":
            count += (ord(char) - ord("g") + 1) * 20
        elif char in "0123456789ABCDEF":
            row += char * (count or 1)
            count = 0
            assert len(row) <= width, "Graphic row overflow"
        elif char in ",!":
            assert count == 0
            row += ("0" if char == "," else "F") * (width - len(row))
        elif char == ":":
            assert not row and rows and count == 0
            rows.append(rows[-1])
        else:
            raise AssertionError(f"Unexpected graphic character: {char}")
        if len(row) == width:
            rows.append(row)
            row = ""
    assert not row and count == 0
    raw = bytes.fromhex("".join(rows))
    assert len(raw) == total, (len(raw), total)
    bitmap = Image.frombytes("1", (row_bytes * 8, len(rows)), raw)
    return ImageOps.invert(bitmap.convert("L"))


def check(stem, expected):
    raw_zpl = stem.with_suffix(".zpl").read_bytes()
    zpl = raw_zpl.decode()
    assert zpl.count("^PQ1") == 1
    assert len(raw_zpl) < 65536
    preview = Image.open(stem.with_suffix(".png")).convert("L")
    scale = preview.width // 576
    preview = preview.resize((576, 508), Image.Resampling.NEAREST)
    canvas = Image.new("L", (576, 508), 255)
    graphics = re.findall(r"\^FO(\d+),(\d+)\^GFA,(\d+),(\d+),(\d+),([^\^]+)\^FS", zpl)
    assert graphics, "No printer graphics found"
    for x, y, total, uncompressed, row_bytes, data in graphics:
        x, y, total, uncompressed, row_bytes = map(int, (x, y, total, uncompressed, row_bytes))
        assert total == uncompressed
        bitmap = decode_graphic(data, row_bytes, total)
        assert x >= 20 and y >= 20 and x + bitmap.width <= 556 and y + bitmap.height <= 492
        canvas.paste(bitmap, (x, y))
    # The border/divider are native vector commands. Compare every interior
    # pixel above and below that divider, including all text and barcode areas.
    for box in [(20, 20, 556, 270), (20, 276, 556, 490)]:
        assert ImageChops.difference(preview.crop(box), canvas.crop(box)).getbbox() is None, "Zebra/PNG raster mismatch"
    try:
        import zxingcpp
    except ImportError:
        print("Scanner check skipped: install zxing-cpp to enable it")
    else:
        for name, image in [("PNG", preview), ("Zebra", canvas)]:
            actual = sorted(code.text for code in zxingcpp.read_barcodes(image))
            assert actual == sorted(expected), (name, actual, expected)
    print(f"PASS {stem.name}: identical Poppins raster; {len(expected)} scannable codes; {len(raw_zpl)} ZPL bytes (PNG scale {scale})")


if __name__ == "__main__":
    folder = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).resolve().parents[1] / "docs/issuer-tag"
    common = ["125001234", "(01)RM-100245(17)500(10)GRPO-20261022-01", "PN-67890", "RM-100245"]
    check(folder / "issuer-qr-tag", common + ["WH-20261022-001"])
    check(folder / "issuer-qr-tag-no-wh-lot", common)
