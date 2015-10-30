# Magerun Config Diff

Magerun module for generating diff between local and remote magento installations.

![](http://i.imgur.com/FV7pjeJ.png)

##Requirements
1. Magerun: https://github.com/netz98/n98-magerun
2. Composer
3. php ssh2 extension

## Installation
1. Create `~/.n98-magerun/modules/`
2. Clone this repository to `~/.n98-magerun/modules/`

        cd ~/.n98-magerun/modules/
        git clone https://github.com/orkz/magerun-config-diff.git
3. Install dependencies
        
        cd ~/.n98-magerun/modules/magerun-config-diff
        composer install

## Usage

Run this command from your local Magento root folder

    $ magerun scandi:config-diff user@host:/path/to/magento/root
    
## Options

1. `--password=<password>` (Optional) Set password as an optoin (Not recommended).
2. `--column-width=<width>` (Default: 50) Maximum column width in console output.


## TODO
1. Implement ssh key authentication support. Currently works only with passwords.
