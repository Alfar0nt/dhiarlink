# Dhiarlink

> A self-hosted URL shortener with CLI and REST interfaces, forked from [Shlink](https://shlink.io).

A PHP-based self-hosted URL shortener that can be used to serve shortened URLs under your own domain.

**Landing page:** [https://dhiarr.qzz.io](https://dhiarr.qzz.io)
**Dashboard:** [https://app.dhiarr.qzz.io](https://app.dhiarr.qzz.io)

## Table of Contents

- [Docker image](#docker-image)
- [Self-hosted](#self-hosted)
    - [Download](#download)
    - [Configure](#configure)
- [Using Dhiarlink](#using-dhiarlink)
- [Contributing](#contributing)

## Docker image

You can run Dhiarlink using the official Docker image. Generate a container and provide custom config via environment variables.

## Self-hosted

First, make sure the host where you are going to run Dhiarlink fulfills these requirements:

* PHP 8.4 or 8.5
* The next PHP extensions: json, curl, pdo, intl, gd and gmp/bcmath.
    * apcu extension is recommended if you don't plan to use RoadRunner.
    * sockets and bcmath extensions are required if you want to integrate with a RabbitMQ instance.
* MySQL, MariaDB, PostgreSQL, MicrosoftSQL or SQLite.
    * You will also need the corresponding pdo variation for the database you are planning to use: `pdo_mysql`, `pdo_pgsql`, `pdo_sqlsrv` or `pdo_sqlite`.

### Download

In order to run Dhiarlink, you will need a built version of the project. There are two ways to get it.

* **Using a dist file**

    The easiest way to install Dhiarlink is by using one of the pre-bundled distributable packages.

    Go to the releases page and download the `dhiarlink*_dist.zip` file that suits your needs. You will find one for every supported PHP version.

    Finally, decompress the file in the location of your choice.

* **Building from sources**

    If for any reason you want to build the project yourself, follow these steps:

    * Clone the project with git, or download it by clicking the **Clone or download** green button.
    * Download the [Composer](https://getcomposer.org/download/) PHP package manager inside the project folder.
    * Run `./build.sh 1.0.0`, replacing the version with the version number you are going to build (the version number is used as part of the generated dist file name, and to set the value returned when running `dhiarlink -V` from the command line).

    After that, you will have a dist file inside the `build` directory, that you need to decompress in the location of your choice.

### Configure

Despite how you built the project, you now need to configure it, by following these steps:

* If you are going to use MySQL, MariaDB, PostgreSQL or Microsoft SQL Server, create an empty database with the name of your choice.
* Recursively grant write permissions to the `data` directory. Dhiarlink uses it to cache some information.
* Set up the application by running the `vendor/bin/shlink-installer install` script. It is a command line tool that will guide you through the installation process. **Take into account that this tool has to be run directly on the server where you plan to host Dhiarlink. Do not run it before uploading/moving it there.**
* Generate your first API key by running `bin/cli api-key:generate`. You will need the key in order to interact with Dhiarlink's API.

## Using Dhiarlink

Once Dhiarlink is installed, there are two main ways to interact with it:

* **The command line**: Try running `bin/cli` to see all the available commands.

    All of them can be run with the `--help`/`-h` flag in order to see how to use them and all the available options.

    It is probably a good idea to symlink the CLI entry point (`bin/cli`) to somewhere in your path, so that you can run Dhiarlink from any directory.

* **The REST API**: The complete docs on how to use the API can be found at [https://dhiarr.qzz.io/docs](https://dhiarr.qzz.io/docs).

    However, you probably don't want to consume the raw API yourself. That's why a web client is available at [https://app.dhiarr.qzz.io](https://app.dhiarr.qzz.io), or you can host it yourself.

Both the API and CLI allow you to do mostly the same operations, except for API key management, which can be done from the command line interface only.

## Contributing

If you are trying to find out how to run the project in development mode or how to provide contributions, read the [DEVELOPING](DEVELOPING.md) doc.

---

> This product includes GeoLite2 data created by MaxMind, available from [https://www.maxmind.com](https://www.maxmind.com)

> Dhiarlink is a fork of [Shlink](https://shlink.io) by Alejandro Celaya Alastrué.
