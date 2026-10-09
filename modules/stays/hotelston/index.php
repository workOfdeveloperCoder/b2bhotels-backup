<?php

global $router;

if (is_file(__DIR__ . '/occupancy.php')) { include_once __DIR__ . '/occupancy.php'; }

include 'creds.php';
include 'search.php';
include 'details.php';
include 'rooms.php';
include 'checkavailability.php';

include 'actions/issue.php';
include 'actions/cancel.php';
include 'actions/void.php';
include 'actions/refund.php';

include 'content/content.php';
