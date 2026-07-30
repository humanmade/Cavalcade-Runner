<table width="100%">
	<tr>
		<td align="left" width="70">
			<strong>Cavalcade-Runner</strong><br />
			Daemon for Cavalcade, a scalable WordPress jobs system.
		</td>
		<td align="right" width="20%">
			<a href="https://travis-ci.org/humanmade/Cavalcade-Runner">
				<img src="https://travis-ci.org/humanmade/Cavalcade-Runner.svg?branch=master" alt="Build status">
			</a>
		</td>
	</tr>
	<tr>
		<td>
			A <strong><a href="https://hmn.md/">Human Made</a></strong> project. Maintained by @rmccue.
		</td>
		<td align="center">
			<img src="https://hmn.md/content/themes/hmnmd/assets/images/hm-logo.svg" width="100" />
		</td>
	</tr>
</table>

## What?

This is the runner for Cavalcade. Head over to the [Cavalcade repo](https://github.com/humanmade/Cavalcade) to learn
more about running this.

## Configuration

| Environment variable     | Default | Description                                     |
| ------------------------ | ------- | ----------------------------------------------- |
| `CAVALCADE_MAX_WORKERS`  | `4`     | Maximum number of jobs to run concurrently.     |

Each worker spawns a full WordPress process, so the worker count is usually what determines the Runner's peak memory
use. On a host with limited memory — a small container instance, for example — 4 concurrent workers can exceed available
RAM, and the resulting swapping is often far more expensive than the reduced cron throughput would have been. Lower
`CAVALCADE_MAX_WORKERS` to match the host.

Values that are not positive integers are ignored, with a warning on `STDERR`, and the default is used.
