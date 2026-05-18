<div class="exif-panel">
    <h3>Photo Details</h3>

    {if !empty($exif.date_taken_unix)}
    <div class="exif-row">
        <span class="exif-label">Date Taken</span>
        <span class="exif-value">{$exif.date_taken_unix|date_format:"%Y-%m-%d %H:%M"}</span>
    </div>
    {/if}

    {if !empty($exif.camera_make) || !empty($exif.camera_model)}
    <div class="exif-row">
        <span class="exif-label">Camera</span>
        <span class="exif-value">{if !empty($exif.camera_make)}{$exif.camera_make} {/if}{$exif.camera_model}</span>
    </div>
    {/if}

    {if !empty($exif.lens)}
    <div class="exif-row">
        <span class="exif-label">Lens</span>
        <span class="exif-value">{$exif.lens}</span>
    </div>
    {/if}

    {if !empty($exif.focal_length)}
    <div class="exif-row">
        <span class="exif-label">Focal Length</span>
        <span class="exif-value">{$exif.focal_length}</span>
    </div>
    {/if}

    {if !empty($exif.aperture)}
    <div class="exif-row">
        <span class="exif-label">Aperture</span>
        <span class="exif-value">{$exif.aperture}</span>
    </div>
    {/if}

    {if !empty($exif.shutter_speed)}
    <div class="exif-row">
        <span class="exif-label">Shutter Speed</span>
        <span class="exif-value">{$exif.shutter_speed}</span>
    </div>
    {/if}

    {if !empty($exif.iso)}
    <div class="exif-row">
        <span class="exif-label">ISO</span>
        <span class="exif-value">{$exif.iso}</span>
    </div>
    {/if}

    {if !empty($exif.width) && !empty($exif.height)}
    <div class="exif-row">
        <span class="exif-label">Dimensions</span>
        <span class="exif-value">{$exif.width} × {$exif.height}</span>
    </div>
    {/if}

    {if !empty($exif.orientation_name)}
    <div class="exif-row">
        <span class="exif-label">Orientation</span>
        <span class="exif-value">{$exif.orientation_name}</span>
    </div>
    {/if}

    {if !empty($exif.gps_lat) && !empty($exif.gps_lng)}
    <div class="exif-row">
        <span class="exif-label">Location</span>
        <span class="exif-value">{$exif.gps_lat}, {$exif.gps_lng}</span>
    </div>
    {/if}
</div>
