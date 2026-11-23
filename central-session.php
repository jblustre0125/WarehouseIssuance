<?php
// Warehouse-owned compatibility shim for explicit or cached prepend bootstraps.
require_once __DIR__ . '/includes/central-auth.php';
if (whCallbackRequest()) return;
if (whSelectCentralSession()) return;
whResumeLegacyNative();
