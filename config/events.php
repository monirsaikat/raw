<?php

// Event → listeners registered at boot. Listeners are class names in
// listeners/ (handle() receives the payload), 'Class@method', or global
// function names. Wildcards work: 'user.*' => ['AuditListener'].
// Scaffold one with: php console.php make:listener SendWelcomeMail --event=user.registered

return [
    // 'user.registered' => ['SendWelcomeMail'],
];
