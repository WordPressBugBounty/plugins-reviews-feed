import { __ } from "@wordpress/i18n";
import Button from "../../../../Common/Button";
import Notice from "../../../../Global/Notice";

const CollectionEmpty = (props) => {
	const isModal = props?.modal && props?.modal === true,
		  isFree = props?.free && props?.free === true,
		  type  = isModal || isFree ? 'content-empty' : 'content-list'
    const utmSource = 'reviews-free'

	return (
		<>
			<div className='sb-single-collection-empty sb-fs' data-ismodal={isModal}>
				<div className='sb-single-collection-icon sb-fs'>
					{
						type === 'content-empty' &&
						<img src={ window.sb_customizer.assetsURL + 'sb-customizer/assets/images/collections-splash.svg' } alt={__( 'Add reviews to your collection', 'reviews-feed' )} />
					}
					{
						type === 'content-list' &&
						<img src={ window.sb_customizer.assetsURL + 'sb-customizer/assets/images/add-reviews-collections.svg' } alt={__( 'Add reviews to your collection', 'reviews-feed' )} />
					}
				</div>
				<div className='sb-single-collection-empty-text'>
					{
						type === 'content-empty' &&
						<>
							<h3 className='sb-h3 sb-fs'>{ __('Organize reviews into collections, embed collections on any page', 'reviews-feed') }</h3>
							<p className='sb-small-p sb-light-text sb-fs'>{ __('Collections help you organize your reviews in collections such as “Homepage reviews” or “Product reviews”. You can then use them as a reviews source in any feed.', 'reviews-feed') }</p>
						</>
					}
					{
						type === 'content-list' &&
						<>
							<h3 className='sb-h3 sb-fs'>{ __('Add your first review to this collection', 'reviews-feed') }</h3>
							<p className='sb-small-p sb-light-text sb-fs'>{ __('Add reviews from Google, Facebook, TrustPilot or Yelp. Collections help you hand-pick reviews and embed them on pages.', 'reviews-feed') }</p>
						</>
					}
					<div className='sb-single-collection-utm-btns sb-fs'>
						{
							isModal === false &&
							<>
								<Button
									size='medium'
									full-width='true'
									type='primary'
									text={ __('Upgrade to Pro', 'reviews-free') }
									icon='chevron-right'
									icon-position='right'
									iconSize='8'
									link={'https://smashballoon.com/pricing/reviews-feed/?utm_campaign='+utmSource+'&utm_source=collections-page&utm_medium=lite-upgrade-footer-cta&utm_content=UpgradeToPro'}
									target='_blank'
								/>
								<Notice
									customClass='sb-upgradecollection-lite-banner-btn'
									icon='tag'
									iconSize='18'
									heading={ __( 'Lite Plugin Users get 50% OFF.', 'reviews-free' ) }
									text={ __( '(auto-applied at checkout)', 'reviews-free' ) }
									link={'https://smashballoon.com/pricing/reviews-feed/?utm_campaign='+utmSource+'&utm_source=collections-page&utm_medium=lite-upgrade-footer-cta&utm_content=UpgradeToPro'}
									target='_blank'
								/>
							</>
						}
					</div>
				</div>
			</div>
			<div className='sb-single-collection-empty-works sb-fs'>
				<h4 className='sb-h4 sb-fs'>{ __('How it works', 'reviews-feed') }</h4>
				<div className='sb-single-collection-steps sb-fs'>

					<div className='sb-single-collection-step-item'>
						<div className='sb-single-collection-step-item-icon'>
							<img src={ window.sb_customizer.assetsURL + 'sb-customizer/assets/images/collection-step1.svg' } alt={__( 'Handpick reviews', 'reviews-feed' )} />
						</div>
						<div className='sb-single-collection-step-item-text'>
							<strong className='sb-fs'>{ __('Handpick reviews', 'reviews-feed') }</strong>
							<p className='sb-small-p '>{ __('Create handpicked reviews collections to make the best first impression.', 'reviews-feed') }</p>
						</div>
					</div>

					<div className='sb-single-collection-step-item'>
						<div className='sb-single-collection-step-item-icon'>
							<img src={ window.sb_customizer.assetsURL + 'sb-customizer/assets/images/collection-form-connection.svg' } alt={__( 'Add them manually', 'reviews-feed' )} />
						</div>
						<div className='sb-single-collection-step-item-text'>
							<strong className='sb-fs'>{ __('Or get them from your audience', 'reviews-feed') }</strong>
							<p className='sb-small-p '>{ __('You can also connect a form from WPForms or Formidable forms.', 'reviews-feed') }</p>
						</div>
					</div>

					<div className='sb-single-collection-step-item'>
						<div className='sb-single-collection-step-item-icon'>
							<img src={ window.sb_customizer.assetsURL + 'sb-customizer/assets/images/embed-collections.svg' } alt={__( 'Embed in a feed', 'reviews-feed' )} />
						</div>
						<div className='sb-single-collection-step-item-text'>
							<strong className='sb-fs'>{ __('Embed on any feed', 'reviews-feed') }</strong>
							<p className='sb-small-p '>{ __('Add these collections as source on any feed. These work just like any other sources.', 'reviews-feed') }</p>
						</div>
					</div>

				</div>
			</div>

		</>
	)

}
export default CollectionEmpty;