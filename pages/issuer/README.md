# Issuer Pages

Current route: `issuer.php`

Issuer printing and reprinting use a dedicated 3 x 3 inch tag for both Nitto
and Zebra. It includes the existing item/quantity/GRPO-lot payload QR, an item
code QR, and a separate **WH LOT QR** containing the complete, trimmed
`warehouse_lot_no` value without a prefix. Blank WH lots do not create a QR.
The payload format remains `(01)ItemCode(17)Quantity(10)GRPOLotNo`.

Sample design: [updated issuer QR tag](../../docs/issuer-tag/issuer-qr-tag.png).
Regenerate sample PNG and ZPL files without printing or database access:

```powershell
php tools/preview_issuer_tag.php
```
