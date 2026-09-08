<?php

// Thrown by decrypt()/decrypt_string() when a payload is malformed, was
// tampered with, or was encrypted with a different key.

class DecryptException extends RuntimeException
{
}
