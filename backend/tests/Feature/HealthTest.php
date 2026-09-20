<?php

it('reports the control plane health', function (): void {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertExactJson([
            'service' => 'liteboard-control-plane',
            'status' => 'ok',
        ]);
});
