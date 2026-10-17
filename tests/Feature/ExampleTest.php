<?php

test('guests are sent to the sign in page', function () {
    $response = $this->get('/');

    $response->assertRedirect('/login');
});
