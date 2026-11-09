# Examples — snapWONDERS PHP SDK

Runnable, self-contained examples. Each needs an API key
([sign up free](https://snapwonders.com/sign-up)) in the `SNAPWONDERS_API_KEY` environment variable.

```bash
composer install                 # from the package root, installs the autoloader
export SNAPWONDERS_API_KEY=sw_your_key_here

php examples/hide_and_reveal.php     # steganography: hide a file in an image, then reveal it
php examples/analyse.php             # forensic analysis: grade an image A–F + overlay assets
php examples/convert.php             # media conversion: PNG → WebP
```

Outputs are written to `examples/out/` (git-ignored). `assets/sample.png` is a generated placeholder
— pass your own image to `analyse.php` (`php examples/analyse.php my-photo.jpg`) or swap the file in
`assets/` for richer results.
