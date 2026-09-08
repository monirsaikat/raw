<?php

// Queue helpers. Jobs are classes extending Job (php console.php make:job);
// which driver runs them comes from config/queue.php (QUEUE_CONNECTION).
// See docs/queue.html.

// Pushes a job onto its queue and returns the driver's id.
function dispatch(Job|Closure $job): string
{
    return Queue::push($job);
}

// Alias kept for code written against the first release.
function dispatch_job(Job|Closure $job): string
{
    return Queue::push($job);
}

// Pushes a job that becomes available after $seconds.
function dispatch_later(int $seconds, Job $job): string
{
    return Queue::later($seconds, $job);
}

// Runs a job right now in this process, whatever the configured driver.
function dispatch_sync(Job $job): string
{
    return (new SyncQueue())->push($job->queue ?? Queue::defaultQueue(), serialize($job));
}
