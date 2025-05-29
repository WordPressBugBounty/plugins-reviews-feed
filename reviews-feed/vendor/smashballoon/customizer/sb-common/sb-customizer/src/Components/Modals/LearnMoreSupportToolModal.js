import { useContext, useMemo, useState } from 'react'
import { __ } from '@wordpress/i18n'
import Button from '../Common/Button';
import SbUtils from '../../Utils/SbUtils';

const LearnMoreSupportToolModal = (props) => {
    const currentContext =  SbUtils.getCurrentContext();
	const supportToolContent = [
		{
			heading : __('What are they used for?', 'reviews-feed'),
			text : __('Solving an issue in your plugin might sometime require testing API access but with your setup. We do not want to expose your API keys over support messages and hence we use a temporary login link system to securely access it.', 'reviews-feed')
		},
		{
			heading : __('What can a support executive access?', 'reviews-feed'),
			text : __('A support team member can only access Smash Balloon plugin to make API requests. They can NOT access any other plugins, create posts or in any way modify your WordPress website.', 'reviews-feed')
		},
		{
			heading : __('Can I disable or delete the temporary login link?', 'reviews-feed'),
			text : __('The login link is auto-destroyed in 14 days. You can also manually delete it any time you want.', 'reviews-feed')
		}
	];

	return (
        <div className="sb-learnmore-stool-modal sb-fs">
            <div className="sb-learnmore-stool-heading sb-fs">
                <h3 className='sb-h3'>{ __( 'Temporary Login Links', 'sb-customizer' ) }</h3>
            </div>
            <div className="sb-learnmore-stool-center sb-fs">
				<div className="sb-learnmore-stool-icon sb-fs">{SbUtils.printIcon('key', '', false, 30)}</div>
			</div>
            <div className="sb-learnmore-stool-content sb-fs">
				{
					supportToolContent.map((item, key) => {
						return (
							<div className='sb-learnmore-stool-item' key={key}>
								<div className='sb-learnmore-stool-item-num'>{key + 1}</div>
								<div className='sb-learnmore-stool-item-text'>
									<strong className='sb-bold sb-text-small'>{item.heading}</strong>
									<div className='sb-small-p'>{item.text}</div>
								</div>
							</div>
						)
					})
				}
			</div>
            <div className="sb-learnmore-stool-bottom sb-fs">
				<Button
					type='primary'
					text={__('Dismiss', 'reviews-feed')}
					icon='close'
					size='medium'
					iconSize='12'
					onClick={() => {
						props.onCancel()
					}}
				/>
			</div>
		</div>
	)
}

export default LearnMoreSupportToolModal;