<?php
/**
 * Convenience entry point.
 * The application itself lives in /public; this redirect lets
 * http://localhost/UniPortal/ open the login page directly instead
 * of showing an Apache directory listing.
 */
header('Location: public/index.php', true, 302);
exit;
