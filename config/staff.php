<?php

/*
| The staff app ("826 Team"). Quick replies are fixed texts the team can drop
| into a chat: nothing here is invented shop information. A reply is only
| offered when its text is set and the branch has what it needs ("needs":
| "map" = the branch has a map link). The price list has no text yet, so
| its chip is not offered. {name} is the customer's first name when known;
| {branch} is the branch's name.
*/
return [
    'quick_replies' => [
        'slot_confirmed' => ['label' => 'Slot confirmed', 'text' => 'Hi {name}, your slot at 826 {branch} is confirmed. See you!', 'needs' => null],
        'price_list' => ['label' => 'Send price list', 'text' => null, 'needs' => null],
        'location' => ['label' => 'Share location', 'text' => 'Here is where to find 826 {branch}: {map}', 'needs' => 'map'],
    ],
];
