# Issuer Pages

Current route: `issuer.php`

Issuer printing and reprinting use a dedicated 3 x 2.5 inch tag (576 x 508
printer dots) for both Nitto and Zebra. It includes the existing
item/quantity/GRPO-lot payload QR, a **PART NUMBER QR** using `parts_code`
(or `part_number`), an item code QR, and a separate **WH LOT QR** containing the complete, trimmed
`warehouse_lot_no` value without a prefix. Blank WH lots do not create a QR.
The payload format remains `(01)ItemCode(17)Quantity(10)GRPOLotNo`.

Labels use bundled Poppins Medium and SemiBold from `assets/fonts/`, under
the accompanying SIL Open Font License. Deploy both TTFs with
`includes/issuer_tag.php`. PHP GD with FreeType is required. Both print paths
use the same monochrome lettering, so the printer does not need Poppins
installed. Headings and captions wrap at a fixed readable size; overflowing
display text ends in `...`, while QR values remain complete. Empty optional
values display `N/A` and do not create a QR. Zebra graphics use the
[documented ASCII repeat encoding](https://cpws.zebra.com/cpws/docs/zpl/zpl_manual.pdf)
to keep Bluetooth payloads small. Each item still prints one label.

Sample design: [updated issuer QR tag](../../docs/issuer-tag/issuer-qr-tag.png).
Regenerate sample PNG and ZPL files without printing or database access:

```powershell
php tools/preview_issuer_tag.php
python tools/check_issuer_raster.py
```

The raster check requires Pillow; installing `zxing-cpp` also enables an
independent scanner check of all QR codes and the reference barcode.
