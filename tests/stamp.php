<?php
// Test entry point: stamps a template given as JSON on stdin ({source, file, secret}) and prints the
// result as JSON. Lets the package's Node tests drive the scanner without a Laravel app.
require __DIR__ . '/../src/BladeAnchors.php';
$input = json_decode(stream_get_contents(STDIN), true);
echo json_encode(\Ontrackd\Feedback\BladeAnchors::stamp($input['source'], $input['file'], $input['secret'] ?? null));
