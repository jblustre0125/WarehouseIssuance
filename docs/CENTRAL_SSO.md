# Optional NBC Central sign-in for Warehouse Issuance

Central SSO and direct Warehouse passwords remain independent. No schema
migration, account provisioning, SAP changes or printer configuration changes
are required.

## Production configuration

- Application: `C:\Xampp\htdocs\WarehouseIssuance`.
- Central application key: `warehouse`.
- Callback: `https://192.168.23.64/WarehouseIssuance/central-sso.php`.
- Issuer: `https://192.168.20.10:4883/nbc-central`.
- Private connector: `C:\Xampp\nbc-bridges`, outside the web root.

Use the existing client key and CA bundle. Do not copy Central database settings
into Warehouse or rerun a legacy bridge installer. `WH_REMOTE_ROOT` can override
the private connector directory through trusted server configuration. The
registered folder, cookie path and callback must match this installation.

Production uses the existing GET authorization-code, browser state and S256 PKCE
connector flow. Same-host installations can consume the existing bound POST
ticket through a sibling Central installation, optionally selected with
`WH_CENTRAL_ROOT`.

Run the production connector check before rollout:

```powershell
& C:\Xampp\php\php.exe C:\Xampp\nbc-bridges\diagnose.php warehouse
```

The user-provided diagnostic passed with HTTP 200 and TLS result 0 on 2026-11-23.
This checks the CLI runtime; browser sign-in must still verify Apache/PHP and the
actual local account mapping.

## Sessions and permissions

`central-session.php` is application-owned and works both as an explicit auth
include and with cached prepend settings. `includes/auth.php` loads it, so a
prepend directive is not required for SSO.

Default native sessions retain the deployment's PHP session name, storage and
cookie settings. Production's checked root hooks showed no active prepend or
named native-session handler. Existing authenticated `NBC_NATIVE_WAREHOUSE`
sessions from the earlier bridge are resumed in their original
`nbc-warehouse-native-sessions` directory; their identities are not copied into
a new store. Legacy shared Central cookies are not imported into native login.
Anonymous or invalid legacy sessions fall back to the original native bootstrap.

Central uses `WH_STAFF_SSO`, scoped to the application, in a separate
`wh-staff-sso-v1` storage directory. Native password pages never load Central.
Opening direct login and signing in does not clear an earlier SSO session, and
SSO handoff/logout does not clear native sessions.

The mapping reads an existing active `AppUsers.Username` as a string, including
leading zeros. It preserves the native user ID, full name, role, receiver area,
assigned hostname and assigned IP. All seven native roles are supported:
`admin`, `issuer`, `picker`, `warehouse`, `requestor`, `receiver`, `sap_encoder`.
The native index dispatcher chooses each role's landing page. Existing guards,
Warehouse's picker/issuer inheritance and special page permissions remain intact.

Every protected SSO request rechecks the Central grant and reloads the active
Warehouse account before the request handler runs. Role changes and deactivation
affect existing SSO sessions. SSO updates the same last-login metadata as native
login; it does not provision accounts or write business transactions.

## Navigation, APIs and printing

Final links, redirects and forms carry `wh_auth` to retain a tab's sign-in method.
The app's base URL helpers and printed QR URLs are unchanged. GET filters retain
the context through hidden fields. `assets/central-auth.js` provides
`WarehouseAuth.fetch` and `WarehouseAuth.printFetch` for Warehouse pages without
replacing global fetch or the printer client. It preserves request data and adds
context only to same-origin Warehouse PHP endpoints; external printer relays and
other applications retain their URLs.

For older tablet clients' capability checks, a same-origin, same-application
referrer can supply the page's context when explicit parameters are absent.
Explicit parameters take priority. The selected session still undergoes full
authentication, role and grant checks. A foreign referrer cannot supply context.

Public helpers and CLI workers remain independent of Central. API authentication
failures return a bounded JSON error; browser failures offer the direct login.
Central logout returns to the trusted portal URL stored after handoff, even when
the Central API is unavailable. Native logout retains its existing destination.

## Legacy installer opt-out and SSO shutdown

The existing `.nbc-central-disabled` file is retained as the **legacy installer
opt-out**. Its text explains that application-owned optional SSO is enabled. This
prevents older repair/install commands from overwriting the app-owned integration.
It does not disable the app-owned SSO flow.

To temporarily disable this SSO implementation, create an empty
`.warehouse-sso-disabled` file in the app root. Native login and existing native
sessions keep working. Remove that exact marker to re-enable SSO. The marker is
denied direct HTTP access in `.htaccess`.

## Tests and deployment

```powershell
composer test:sso
& C:\Xampp\php\php.exe tests\central-sso.php --connector-root=C:\Xampp\nbc-bridges
```

The PHP suite uses fixture SQL and connector responses, with the native auth
functions, index dispatcher, login/logout and capability endpoint. The optional
connector argument tests the installed connector functions using simulated cURL,
including HTTPS/TLS settings, PKCE exchange and bounded timeouts. Neither command
connects to live SQL/SAP or sends printer jobs. JavaScript tests cover same-origin
URL scoping, retained queries/payloads and printer delegation. The existing tablet
printer tests use mocks and do not perform physical printing.

Local Warehouse already had edits to `.htaccess`, `.user.ini`, the installer
marker and both bridge files before this work. Preserve those edits and private
`includes/config.php`, storage, Android build files and printer configuration.
The reviewed change replaces the legacy session/callback behavior and adds the
explicit auth include. No new prepend setting is needed; previously configured
prepend directives may continue to reference the app-owned compatibility file.

Before deployment, back up the affected application files. Deploy the helper,
compatibility shim, auth include, callback, frontend helper and page changes
together. Keep the private connector registration and CA unchanged.

After deployment, test native login and a Central tile launch in separate tabs.
For each applicable role, verify the landing page and an allowed/forbidden page,
filters, receiving area, AJAX requests and both logout destinations. Keep a native
tab open while launching SSO and confirm it stays signed in. Use authorized test
accounts and approved test labels for any production action that writes business
data or prints. Fixture tests do not establish successful production browser SSO.
