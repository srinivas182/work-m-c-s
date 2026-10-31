<?php

declare(strict_types=1);

use Wurk\Website\Support\Uploads\ClamdScanner;
use Wurk\Website\Support\Uploads\VirusScanner;

// Runs only against a real ClamAV (CI's ClamAV job sets CLAMAV_LIVE=1); otherwise skipped — never faked.
beforeEach(function () {
    if (getenv('CLAMAV_LIVE') !== '1') {
        $this->markTestSkipped('Needs a real ClamAV (set CLAMAV_LIVE=1 and CLAMAV_HOST).');
    }
    config(['wurk.clamav.host' => getenv('CLAMAV_HOST') ?: '127.0.0.1']);
});

it('reports the standard antivirus test file as infected and an ordinary file as clean', function () {
    // EICAR, assembled at run time so the repository never contains it.
    $eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}'.'$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!'.'$H+H*';
    $scanner = new ClamdScanner;
    expect($scanner->scan($eicar))->toBe(VirusScanner::INFECTED)
        ->and($scanner->scan('An ordinary note about a roof.'))->toBe(VirusScanner::CLEAN);
})->group('clamav');
