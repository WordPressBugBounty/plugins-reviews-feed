import { useContext, useMemo, useState } from 'react'
import { __ } from '@wordpress/i18n'
import SbUtils from '../../Utils/SbUtils';

const BulkHistoryModal = ( props ) => {
	return (
		<div className='sb-redirecting-modal-ctn sb-fs'>
				<div className='sb-redirecting-modal-icon sb-fs'>
					<div className='sb-fetch-modal-icon'>
						{ SbUtils.printIcon('fetch', false, false, 90) }
					</div>
				</div>
				<div className='sb-redirecting-modal-text sb-fs'>
					{
						<h4 className='sb-h4 sb-fs'>{__('Fetching Reviews','reviews-feed')}</h4>
					}
					{
						<p className='sb-standard-p sb-dark2-text sb-fs'>{__('We are fetching your reviews history in the background!','reviews-feed')}</p>
					}
				</div>
			</div>
	)
}

export default BulkHistoryModal;