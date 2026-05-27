-- Step Wave 2 (post-O.7): Recorder process-restart resilience
-- Adds a pid column so the Recorder can recover state after a
-- worker process restart: if the ffmpeg child PID stored here is
-- still alive (`posix_kill($pid, 0)` returns true) the recording
-- is re-attached in memory; otherwise it is marked failed with
-- `error_message = 'process restart'` and the onComplete callbacks
-- fire (so DVR conflict reset, comskip skip, etc. still run).
--
-- `failure_reason` is stored in the existing `error_message` column.

-- One ALTER per clause so re-runs (where 012a already added these)
-- fail independently per duplicate rather than aborting the whole batch.
ALTER TABLE livetv_recordings ADD COLUMN pid INT NULL AFTER status;
ALTER TABLE livetv_recordings ADD INDEX idx_pid (pid);
