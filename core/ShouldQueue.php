<?php

// Marker interface: a listener implementing it is pushed to the queue (when
// the queue module is installed) instead of running during the request.

interface ShouldQueue
{
}
