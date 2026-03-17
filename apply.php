<?php
/**
 * apply.php - Redirects to /archive (self-serve archiving)
 *
 * The old application form is replaced by direct self-serve archiving.
 * Any logged-in Twitch user can archive any channel at /archive.
 */
header('Location: /archive', true, 301);
exit;
