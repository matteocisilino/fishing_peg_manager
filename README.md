# Weight Units & Formats #
Currently, weight management in the application is handled using the metric decimal format. Support is being developed for the following formats:
- **Traditional British format** (e.g., `12 lb 8 oz`)
- **British decimal format** (e.g., `12.5 lb`)
the APP is absolutly BETA




# Fishing Tournament Manager
**"Be Your Own Tournament Master."**

Fishing is more than just a sport; it's a community, a shared passion, and for many, a way of life. *Fishing Tournament Manager* was born from the muddy banks and early mornings by the water, created specifically for passionate anglers, club organizers, and commercial fishery owners who want to bring their events to life. While official federations play a vital role in our sport, we realized that independent organizers needed a dedicated tool to host their own unique competitions with the same level of professionalism—without the heavy bureaucracy.

This software puts the power directly in your hands. Imagine organizing a quick Sunday morning pool match with your friends, where weights are updated on your phone right at the peg. Picture a massive multi-day, multi-lake festival drawing hundreds of anglers, where technical sectors, penalties, and live results are handled effortlessly by multiple marshals at the same time. Whether it's a small local derby or the biggest event your fishery has ever seen, this platform scales to match your ambition.

### Endless Possibilities
- **True Independence**: Run your events your way. You have complete control to tailor small or large competitions outside the traditional circuits, setting the rules that work best for your community.
- **Ultimate Flexibility**: Effortlessly manage any format. From a simple single-day match in your local commercial fishery to complex, sprawling tournaments spread across multiple days and venues.
- **Collaborative & Real-Time**: Say goodbye to paper sheets. Multiple weigh-in marshals can input catches simultaneously from their phones, generating real-time notifications and keeping the excitement alive for everyone watching the progress.
- **Professional Management**: Handle the tough stuff automatically. The software smoothly calculates penalties, manages complex scoring systems, and handles technical sectors just like the biggest international events.
- **Instant Results**: Generate and print intermediate and final results instantly. Add your own custom logo or sponsor graphics to give your printouts a premium, professional touch.
- **Deep Historical Tracking**: Don't just run an event; build a legacy. Keep a permanent historical track record of results for every single peg, an entire bank, or the whole venue over time. Discover which peg truly is the "flyer" in different conditions!
- **Data-Driven Insights**: Retrieve historical weather situations to analyze how wind and temperature trends affect catches. You can even load past data and generate detailed reports for your venue's biologist to study fish health, movement, and feeding behavior.

### The Workflow of a Tournament Master
Running an event should be as smooth as casting a line. Here is how simple it is from start to finish:
1. **Prepare**: Set up your tournament in minutes. Define the lakes, draw the zones, set your peg limits, and add your participants. The system flags duplicates and manages capacity for you.
2. **Play**: Generate the draw automatically or input it manually. As the match begins, marshals head to the bank with just their smartphones. 
3. **Get & Set Results**: Walk the bank and input weights directly at the peg. The system instantly locks zones, records the exact time of the weigh-in, and updates the live leaderboard.
4. **Reward**: The final horn blows. With a single click, consolidate the data and instantly generate beautifully formatted PDF results, complete with sector wins, overall standings, and any applied penalties. Hand out the trophies while the anglers are still packing away their rods!

### Simplicity & Portability
You are never tied to a desk. **Consolidated historical data is always available anywhere.** Because the software is web-based and highly responsive, you can review past tournaments, analyze peg statistics, or manage an ongoing live weigh-in from your phone while standing in the rain, from a tablet in the fishery clubhouse, or from your desktop at home. 

Organize, track, and elevate your fishing tournaments. Just you, your passion, and the water.

---

## 1) Quick Start & Installation

Getting started is designed to be as simple as possible, even for shared hosting environments. 

### Method A: Standalone Web Installer (Recommended for Shared Hosting)
You don't need SSH access, Composer, or Node.js. Just follow these steps:
1. Download the `install.php` file from the `public/` directory of this repository.
2. Upload `install.php` to your web server's public directory (e.g. `public_html`).
3. Navigate to `http://your-domain.com/install.php` in your web browser.
4. The wizard will automatically download the latest application code from GitHub, configure your database, and run all setup tasks!

*(Note for the organizer: For the installer to work flawlessly on hosts without Composer, ensure the GitHub release URL points to a pre-compiled ZIP containing the `vendor` and `public/build` folders).*

### Method B: Advanced Setup (For VPS or Local Development)
If you have SSH access and prefer setting it up manually:
1. Clone the repository and install the basic dependencies: 
   ```bash
   composer install
   npm install && npm run build
   ```
2. Start your local server (`php artisan serve`) or point your web server to the `public/` directory.
3. Open your browser and navigate to the `/install` route. Fill out the simple visual form, and the system will automatically generate your `.env` file and prepare the database for you.

## 2) Main Commands
- `composer install` · installs PHP dependencies.
- `npm install && npm run build` · compiles frontend assets.
- `php artisan migrate` · applies database migrations.
- `php artisan serve` · starts the local development server.
- `php artisan queue:work --queue=meteosat` · worker for Meteosat jobs (see `supervisor.d/meteosat-worker.conf`).
- `php artisan weights:results-pdf {EVENT_ID} [--path=/tmp/file.pdf]` · generates placements PDF for a given event.
- `php artisan schedule:run` · executes scheduled tasks (if using cron).

## 3) Operational Workflows
### Pool
1. Create a pool tournament, choose the structure and lakes, and set `Maximum number of participants`.
2. In the "Zones and pegs" section, select pegs via the ON/OFF toggle buttons (a counter is shown next to the label). You cannot exceed `pool_capacity`.
3. Add participants: duplicates are flagged, and the limit follows the available pegs/capacity. While editing, you can mark a participant as "Absent": upon saving, the name is removed, freeing up a spot.
4. Insert the single event and save. When the event closes, Meteosat jobs are scheduled for 15:00 on the day of the event.

### Individual Tournaments
1. Create an individual tournament, select structure/lakes, and define sectors.
2. Define the zones (supported peg prefixes).
3. Add participants, avoiding duplicates; the limit is based on available pegs.
4. Generate draws and enter weights for each event.

### Team Tournaments
1. Create a team tournament, set team size and zones.
2. Add teams and participants: each team member must have a unique zone within the team.
3. Generate draws and enter weights for each event.

## 4) Artisan Commands (Main)
- `php artisan migrate` · executes all migrations.
- `php artisan migrate:rollback` · rolls back the latest migration batch.
- `php artisan db:seed` · runs database seeders (if any).
- `php artisan queue:work --queue=meteosat` · processes Meteosat jobs.
- `php artisan schedule:run` · runs scheduled tasks (via cron).
- `php artisan weights:results-pdf {event_id} [--path=/file.pdf]` · exports the placements PDF for an event.
- `php artisan optimize` / `config:cache` / `route:cache` · standard optimizations.
- `php artisan tinker` · interactive shell.

## 5) Weight Units & Formats
Currently, weight management in the application is handled using the metric decimal format. Support is being developed for the following formats:
- **Traditional British format** (e.g., `12 lb 8 oz`)
- **British decimal format** (e.g., `12.5 lb`)
