<?php

it('pins the PostgreSQL session to UTC so naive timestamps are not read in the server timezone', function (): void {
    // Laravel binds timestamps as zone-less UTC strings; without this, a server on another timezone
    // would store every timestamptz written by Laravel shifted by its UTC offset.
    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('database.connections.pgsql.timezone'))->toBe('UTC');
});
