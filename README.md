# Google Analytics 4 Module for ThirtyBees
A simple module that adds Google Analytics 4 tracking to your ThirtyBees store, including automatic purchase tracking.

Installation

    1. Upload the module to /modules/analytics4/
    2. Go to Modules → Modules & Services in your admin panel
    3. Find "Google Analytics 4" and click Install

Configuration

    1. Click Configure on the module
    2. Enter your GA4 Measurement ID
    3. Click Save

Finding Your GA4 Measurement ID

    1. Log into Google Analytics
    2. Click Admin (gear icon, bottom left)
    3. In the Property column, click Data Streams
    4. Click your web data stream
    5. Copy the Measurement ID (looks like G-XXXXXXXXX)

What Gets Tracked

    - Page views on all pages
    - Purchase events when customers complete orders
    - Product details (name, price, quantity)
    - Transaction totals and currency

# Verifying It Works
Check Installation

Visit your store and view the page source (right-click → View Page Source). Search for your Measurement ID - you should see it loaded in a script tag.
Check Purchase Tracking

    1. Place a test order
    2. On the order confirmation page, press F12 to open browser console
    3. Under the Network tab, filter for "collect" and confirm a request fires with en=purchase

Check in Google Analytics

    1. Go to Reports → Realtime in Google Analytics
    2. Place a test order
    3. Within 30 seconds, you should see the purchase event

Purchase data appears in standard reports after 24-48 hours under Reports → Monetization → Ecommerce purchases.

# Troubleshooting
Events not showing in GA4?

    - Verify your Measurement ID is correct (format: G-XXXXXXXXX)
    - Check that it's not UA-XXXXXXX (that's Universal Analytics, not GA4)
    - Test in incognito mode (ad blockers can block tracking)

No console message on order confirmation?

    - Clear ThirtyBees cache in Advanced Parameters → Performance
    - Check your PHP error logs

# Support

Copyright (c) 2026 LinuxISP (Pty) Ltd
Author

Ruben Venter - Linuxweb
ruben@linuxweb.co.za


# !Disclaimer!
Even though we have tested the safety of this software, using this plugin will be at your own risk and Linuxweb will not be held responsible in a case of lost data or any other damage.
