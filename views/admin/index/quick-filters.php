<ul class="quick-filter-wrapper">
    <li><a href="#" tabindex="0"><?php
        echo __('Quick Filter'); ?></a>
        <ul class="dropdown">
            <li><span class="quick-filter-heading"><?php
                echo __('Quick Filter') ?></span></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse'); ?>"><?php
                echo __('View All'); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'record_type' => 'Item',
                )); ?>"><?php
                echo __('Only items'); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'record_type' => 'File',
                )); ?>"><?php
                echo __('Only files'); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'items_featured' => 1,
                )); ?>"><?php
                echo __('Featured items'); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'items_public' => 1,
                )); ?>"><?php
                echo __('St: %s', __('Public')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'items_public' => 0,
                )); ?>"><?php
                echo __('St: %s', __('Private')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'status' => BeamInternetArchiveRecord::STATUS_NOT_TO_BEAM_UP,
                )); ?>"><?php
                echo __('St: %s', __('Not to beam up')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'status' => BeamInternetArchiveRecord::STATUS_TO_BEAM_UP,
                )); ?>"><?php
                echo __('St: %s', __('To beam up')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'status' => BeamInternetArchiveRecord::STATUS_TO_UPDATE,
                )); ?>"><?php
                echo __('St: %s', __('To update')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'status' => BeamInternetArchiveRecord::STATUS_TO_REMOVE,
                )); ?>"><?php
                echo __('St: %s', __('To remove')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'public' => BeamInternetArchiveRecord::IS_PUBLIC,
                )); ?>"><?php
                echo __('IA: %s', __('Public')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'public' => BeamInternetArchiveRecord::IS_PRIVATE,
                )); ?>"><?php
                echo __('IA: %s', __('Private')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'Process' => BeamInternetArchiveRecord::PROCESS_COMPLETED,
                )); ?>"><?php
                echo __('Pr: %s', __('Completed')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'process' => array(
                        BeamInternetArchiveRecord::PROCESS_QUEUED,
                        BeamInternetArchiveRecord::PROCESS_QUEUED_WAITING_BUCKET,
                    ),
                )); ?>"><?php
                echo __('Pr: %s', __('Queued')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'process' => array(
                        BeamInternetArchiveRecord::PROCESS_IN_PROGRESS,
                        BeamInternetArchiveRecord::PROCESS_IN_PROGRESS_WAITING_REMOTE,
                    ),
                )); ?>"><?php
                echo __('Pr: %s', __('In progress')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'process' => array(
                        BeamInternetArchiveRecord::PROCESS_FAILED_CONNECTION,
                        BeamInternetArchiveRecord::PROCESS_FAILED_RECORD,
                    ),
                )); ?>"><?php
                echo __('Pr: %s', __('Failed')); ?></a></li>
        </ul>
    </li>
</ul>
