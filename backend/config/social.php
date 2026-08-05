<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Text-preferred platforms
    |--------------------------------------------------------------------------
    |
    | When broadcasting image-type content (e.g. Cause media), platforms
    | listed here receive a text post (title + details + link) instead of
    | the image itself. See App\Services\SocialPlatforms\SocialContentRouter.
    |
    */
    'text_preferred_platforms' => array_values(array_filter(
        explode(',', (string) env('SOCIAL_TEXT_PREFERRED_PLATFORMS', 'linkedin,facebook'))
    )),

];
