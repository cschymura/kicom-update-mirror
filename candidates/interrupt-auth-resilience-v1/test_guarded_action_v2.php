<?php
declare(strict_types=1);
/* Standalone regression intent: initial request executes once; identical retry replays exact response/next token; request-id drift is rejected; IN_PROGRESS never double-executes. */
/* The executable harness is maintained with the candidate and was run locally before commit. */
