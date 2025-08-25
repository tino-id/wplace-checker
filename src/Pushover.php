<?php

namespace App;

class Pushover
{
    private string $token;
    private string $user;

    public function __construct(string $token, string $user)
    {
        $this->token = $token;
        $this->user  = $user;
    }

    public function send(string $message, ?string $file = null)
    {
        $attachment = null;

        if ($file && file_exists($file)) {
            $fileType   = mime_content_type($file);
            $attachment = curl_file_create($file, $fileType);
        }

        $postFields = [
            'token'      => $this->token,
            'user'       => $this->user,
            'message'    => $message,
            'attachment' => $attachment,

        ];

        $ch = curl_init();

        curl_setopt_array(
            $ch,
            [
                CURLOPT_URL            => 'https://api.pushover.net/1/messages.json',
                CURLOPT_POSTFIELDS     => $postFields,
                CURLOPT_RETURNTRANSFER => true,
            ]
        );
        curl_exec($ch);
        curl_close($ch);
    }
}
