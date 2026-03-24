<?php
/**
 * PCPI Staff Dashboard - Secure Links (Loader)
 *
 * This file remains for backward compatibility.
 * It loads the split secure PDF link helpers and the public download endpoint.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/secure-pdf-link.php';
require_once __DIR__ . '/secure-pdf-endpoint.php';
