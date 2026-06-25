NBC Kanban Poka-Yoke Role-Based Version

Files added/modified:
- login.php / logout.php / auth.php: session login and role checking
- issuer.php: Warehouse issuer scan page
- receiver.php: Receiver Kanban scan page
- admin_users.php / user_save.php / user_delete.php: account/user management
- save_issue.php / save_receive.php / save_result.php: save workflows
- view_transactions.php: issuance and receiving record viewer
- get_part_name.php: SAP Business One OITM ItemName lookup by ItemCode
- schema.sql: tables needed in WHPOKAYOKE database

Deployment:
1. Back up your existing project and database.
2. Run schema.sql on the WHPOKAYOKE database.
3. Copy all PHP files into your web app folder.
4. Open login.php.
5. First login: admin / admin123. Change the password immediately.

Notes:
- The receiver module pulls the part name from SAP Business One OITM using ItemCode.
- Issuer QR parser accepts common formats such as (01)PART(17)QTY, PART=xxxx|QTY=100, PN:xxxx|QTY:100.
- Lot number is separate for Issuer and can be scanned as barcode/QR or typed manually.
- Admin can maintain users, roles, receiver area, assigned hostname, and assigned IP address.
