<?php

// A contact-form submission.
class Message extends Model
{
    protected static string $table = 'messages';
    protected static array $fillable = ['name', 'email', 'message'];

    // created_at is filled by the database default; there is no updated_at.
    protected static bool $timestamps = false;
}
