# wplace-checker

This software is a PHP-based command-line tool specifically developed for monitoring and maintaining pixel artworks on the collaborative platform [wplace.live](https://wplace.live).

The tool's core function is to compare local reference images of artworks with their current state on the live platform. If any deviations or damage are detected, the program can automatically send a notification via Pushover to inform the user.

To repair damaged areas, the tool provides a command that determines the exact coordinates and color values ​​of the faulty pixels and outputs them in a format compatible with the wplace.live API.

There are also auxiliary commands, such as the ability to download specific sections of the online canvas. Another useful feature is color analysis, which can be used to check a reference image for colors missing from the official color palette, facilitating the creation and adaptation of artworks.

## Prerequisites

- PHP 8.0 or higher
- GD Extension for PHP
- Curl Extension for PHP
- Composer (for dependencies)

## Installation

1.  Clone the repository
2.  Install dependencies: `composer install`
3.  Add projects

## Available Commands

### 1. Check Command

```bash
php run.php check [project]
```

Checks all projects in the `projects/` directory for differences between local reference images and the current tiles from wplace.live.

**Parameters:**
- `project`: Name of the project to be checked (optional, checks all if not specified)

**Example:**
```bash
php run.php check my-cool-project
```

**Functions:**
- Automatically downloads all necessary tiles for each project
- Compares downloaded tiles with local reference images
- Optionally sends Pushover notifications if differences are found

### 2. Color Check Command

```bash
php run.php color-check [project]
```

Checks which colors are missing.

**Parameters:**
- `project`: Name of the project to be checked

**Example:**
```bash
php run.php color-check project1
```

**Functions:**
- Analyzes all colors in the reference image
- Shows missing color definitions in a tabular format

### 3. Download Image Command

```bash
php run.php download-image [tileX] [tileY] [pixelX] [pixelY] [width] [height]
```

Downloads tiles from wplace.live and crops a specific image area.

**Parameters:**
- `tileX`: Start tile X-coordinate
- `tileY`: Start tile Y-coordinate
- `pixelX`: Start pixel X-coordinate within the first tile
- `pixelY`: Start pixel Y-coordinate within the first tile
- `width`: Width of the area to be cropped in pixels
- `height`: Height of the area to be cropped in pixels

**Example:**
```bash
php run.php download-image 10 15 250 300 500 400
```

**Functions:**
- Automatically downloads all necessary tiles based on the specified coordinates
- Preserves the transparency of the original tiles
- Saves the result as a PNG in the project root directory

### 4. Fix String Command

```bash
php run.php fix-string [project] [pixelcount] [direction] [profile]
```

Provides coordinates and colors for the POST request to wplace.live to repair/build the artwork.

**Parameters:**
- `project`: Name of the project to be repaired
- `pixelcount`: Maximum number of pixels to be repaired
- `direction`: Direction for scanning the pixels (top, bottom, left, right) - Default: "left"
- `profile`: User profile for filtering available colors (optional)

**Examples:**
```bash
# Basic repair of 100 pixels from the left
php run.php fix-string project1 100

# Repair from the top with a specific profile
php run.php fix-string project1 50 top myprofile
```

**Functions:**
- Compares the reference image with the current state
- Scans pixels in the selected direction for an optimal repair sequence
- Filters available colors based on the user profile
- Generates JSON output for the wplace.live API

## Configuration

### Project Configuration

An example configuration is located in the `projects/_example` directory.

Each project requires a PNG file and a `config.yaml` file with the following parameters:

```yaml
tileX: 123           # Start tile X-coordinate
tileY: 456           # Start tile Y-coordinate
offsetX: 100         # Pixel offset X within the tile
offsetY: 200         # Pixel offset Y within the tile
image: "artwork.png" # Name of the reference image in the project directory
disableCheck: false  # Optional: Disable check in the Check Command
```

The values for tile and offset can be taken from [Blue Marble](https://github.com/SwingTheVine/Wplace-BlueMarble), for example.

The PNG file must be the correct size and color palette. Transparent pixels are ignored. The [Wplace Color Converter](https://pepoafonso.github.io/color_converter_wplace/index.html) can be used as a tool.

### Pushover Notifications (Optional)

Create a `config/pushover.yaml` file for notifications:
```yaml
token: "your-pushover-app-token"
user: "your-pushover-user-key"
```

### Project Structure

```
wplace-checker/
- config/
- - colors.yaml       # Color definitions
- - profiles.yaml     # Profiles (optional)
- - pushover.yaml     # Pushover configuration (optional)
- projects/           # Project directory
- - project1/
- - - config.yaml
- - - artwork.png
- - project2/
- - - config.yaml
- - - artwork.png
- src/
- - Commands/         # Source code of the commands
- - Services/         # Services
- - ...
- run.php             # Start CLI script
```

## Debug Mode

For detailed output, use the debug mode with `-vvv` at the end of a command.

```bash
php run.php check -vvv
```