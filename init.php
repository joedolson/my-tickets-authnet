<?php
/**
 * My Tickets: Authorize.net payment gateway
 *
 * @package     My Tickets: Authorize.net
 * @author      Joe Dolson
 * @copyright   2014-2026 Joe Dolson
 * @license     GPLv3
 *
 * @wordpress-plugin
 * Plugin Name: My Tickets: Authorize.net
 * Plugin URI: https://www.joedolson.com/my-tickets/add-ons/
 * Description: Add support for the Authorize.net payment gateway to My Tickets.
 * Author: Joseph C Dolson
 * Author URI: https://www.joedolson.com
 * Text Domain: my-tickets-authnet
 * License:     GPLv3
 * License URI: http://www.gnu.org/license/gpl-2.0.txt
 * Domain Path: lang
 * Version:     1.3.0
 * Requires Plugins: my-tickets
 */

/*
	Copyright 2014-2026  Joe Dolson (email : joe@joedolson.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require __DIR__ . '/src/AuthorizeNet.php';
