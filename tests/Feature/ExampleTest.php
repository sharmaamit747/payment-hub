<?php

test('the application redirects guests to admin login', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('admin.login'));
});
