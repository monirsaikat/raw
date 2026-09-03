<?php

// Set: flash('success', 'Saved!'). Get-and-clear (post-redirect-GET): flash('success').
function flash(string $key, $value = null)
{
    if ($value !== null) {
        session_set("_flash_$key", $value);

        return null;
    }

    $data = session_get("_flash_$key");
    session_forget("_flash_$key");

    return $data;
}
