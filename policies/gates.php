<?php

// Standalone abilities (policies/*Policy.php handle model abilities).
//
//   gate_define('admin', fn (User $user) => (bool) $user->is_admin);
//   gate_define('view-dashboard', fn (?User $user) => $user !== null);  // ?User lets guests in
//   gate_before(fn (?User $user) => $user?->is_superuser ? true : null); // short-circuit
//
// Use them with can('admin'), authorize('admin'), {if 'admin'|can}, or the
// route middleware ['can:admin'].
