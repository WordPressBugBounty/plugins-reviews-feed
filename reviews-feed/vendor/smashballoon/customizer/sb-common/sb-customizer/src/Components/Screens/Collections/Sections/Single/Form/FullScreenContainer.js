import { __ } from "@wordpress/i18n";
import SbUtils from "../../../../../../Utils/SbUtils";
import Button from "../../../../../Common/Button";
import Splash from "./Splash";
import CollectionReviewsForm from "./CollectionReviewForm";
import { useContext } from "react";
import SourceReviewsForm from "./SourceReviewsForm";

const FullScreenContainer = () => {
	const currentContext = SbUtils.getCurrentContext();
    const {
        sbCustomizer,
        curRevF,
		curCl,
		collectionP,
		collectionRv,
		crFormSr,
		sourcesRP,
		curSr,
		reviewsForm,
		slFullScreen,
        editorTopLoader,
        editorNotification
    } = useContext( currentContext );


	const addUpdateReviewCollection = (isDuplicate = false) => {
		const formData = {
            	action 			: 'sbr_feed_saver_manager_addupdate_review_collection',
				page_number 	: collectionP.collectionReviewsPage,
				provider 		: curCl.currentCollection.provider,
				provider_id 	: curCl.currentCollection.account_id,
				is_duplicate 	: isDuplicate ? 1 : 0,
				reviewContent 	: JSON.stringify(curRevF?.currentReviewForm)
			},
            notificationsContent = {
                success : {
                    icon : 'success',
         			text : __('Review Updated successfully', 'reviews-feed' )
               }
        }
		if (SbUtils.checkNotEmpty(curRevF?.currentReviewForm?.reviewer?.first_name)) {
			SbUtils.ajaxPost(
				sbCustomizer.ajaxHandler,
				formData,
				( data ) => { //Call Back Function
					if (curRevF?.currentReviewForm?.new === true) {
		                const newNumber =  parseInt(isNaN(curCl?.currentCollection?.reviews_number) ? 0 : curCl?.currentCollection?.reviews_number) + 1
						curCl?.setCurrentCollection({
							...curCl?.currentCollection,
							'reviews_number' : newNumber
						})
					}
					curRevF.setCurrentReviewForm(null)
					if (data?.postsList) {
						let parsedPostList = data?.postsList.map(element => {
							element = JSON.parse(element.json_data)
							return element;
						});
						collectionRv.setCollectionReviewsList(parsedPostList);
					}

				},
				editorTopLoader,
				editorNotification,
				notificationsContent
			)
		}

	}


	const addReviewsToCollection = () => {
		const formData = {
           	action 			: 'sbr_feed_saver_manager_add_multiple_reviews_collection',
			provider_id 	: curCl.currentCollection.account_id,
			selected_reviews : JSON.stringify(reviewsForm?.selectedReviews)
		},
    	notificationsContent = {
            success : {
            	icon : 'success',
         		text : __('Selected Reviews added to your collection', 'reviews-feed' )
            }
        }
		if (reviewsForm?.selectedReviews !== null && reviewsForm?.selectedReviews.length > 0) {
			SbUtils.ajaxPost(
				sbCustomizer.ajaxHandler,
				formData,
				( data ) => { //Call Back Function
					const newNumber =  parseInt(isNaN(curCl?.currentCollection?.reviews_number) ? 0 : curCl?.currentCollection?.reviews_number) + reviewsForm?.selectedReviews.length
					curCl?.setCurrentCollection({
						...curCl?.currentCollection,
						'reviews_number' : newNumber
					})

					if (data?.postsList) {
						let parsedPostList = data?.postsList.map(element => {
							element = JSON.parse(element.json_data)
							return element;
						});
						collectionRv.setCollectionReviewsList(parsedPostList);
					}
					crFormSr.setCrFormReviewsList(null)
					curSr.setCurrentSourceForm(null)
					reviewsForm.setSelectedReviews([])
				},
				editorTopLoader,
				editorNotification,
				notificationsContent
			)
		}
	}


	return (
		<section className='sb-collection-form-fs sb-fs'>
			<section className='sb-collection-form-content sb-fs'>
				<div className='sb-collection-form-top sb-fs'>
					<div className='sb-collection-form-top-left'>
						{
							crFormSr?.crFormReviewsList === null &&
							<h3
								className='sb-collection-form-heading'
								onClick={() => {
									if( curRevF?.currentReviewForm !== null ) {
										curRevF.setCurrentReviewForm(null)
									} else {
										slFullScreen.setCollectionFullScreen(false)
									}
									sourcesRP.setSourcesFormReviewsPage(1)
								}}
							>
								{ SbUtils.printIcon('chevron-left', false, false, 10) }
								{__('Add Review','reviews-feed')}
							</h3>
						}
						{
							crFormSr?.crFormReviewsList !== null && curSr?.currentSourceForm !== null &&
							<h4
								className='sb-collection-form-heading sb-collection-h4'
								onClick={() => {
									crFormSr.setCrFormReviewsList(null)
									curSr.setCurrentSourceForm(null)
									reviewsForm.setSelectedReviews([])
									sourcesRP.setSourcesFormReviewsPage(1)
								}}
							>
								<strong>
									{ SbUtils.printIcon('chevron-left', false, false, 7) }
									{ curSr?.currentSourceForm?.name }
								</strong>
								<span className='sb-small-p'>
									{ curSr?.currentSourceForm?.provider + ' ' + __('Reviews', 'reviews-feed') }
								</span>
							</h4>
						}
					</div>
					<div className='sb-collection-form-button'>
						{
							curRevF?.currentReviewForm !== null &&
							<>
								{
									curRevF?.currentReviewForm.new !== true &&
									<Button
										customClass='sb-dashboard-btn'
										type='secondary'
										size='small'
										icon='duplicate'
										iconSize='11'
										text={ __( 'Duplicate', 'reviews-feed' ) }
										onClick={ () => {
											addUpdateReviewCollection( true )
										} }
									/>
								}
								<Button
									customClass='sb-dashboard-btn'
									type='primary'
									size='small'
									icon='success'
									iconSize='11'
									text={ curRevF?.currentReviewForm?.new === true ? __( 'Add to Collection', 'reviews-feed' ) : __( 'Save and Exit', 'reviews-feed' ) }
									onClick={ () => {
										addUpdateReviewCollection()
									} }
								/>
							</>
						}
						{
							curRevF?.currentReviewForm === null &&
							curSr?.currentSourceForm !== null &&
							reviewsForm?.selectedReviews !== null &&
							reviewsForm?.selectedReviews.length > 0 &&
							<>
								<Button
									customClass='sb-dashboard-btn'
									type='primary'
									size='small'
									icon='success'
									iconSize='11'
									text={__( 'Add to Collection', 'reviews-feed' )}
									onClick={ () => {
										addReviewsToCollection()
									} }
								/>
							</>

						}
					</div>
				</div>
				<div className='sb-collection-form-bottom sb-fs'>
					{
						curRevF?.currentReviewForm !== null &&
						<CollectionReviewsForm/>
					}
					{
						( curRevF?.currentReviewForm === null && crFormSr?.crFormReviewsList === null ) &&
						<Splash/>
					}
					{
						( curRevF?.currentReviewForm === null && crFormSr?.crFormReviewsList !== null ) &&
						<SourceReviewsForm/>
					}
					{
						curRevF?.currentReviewForm !== null &&
						<div className='sb-collection-form-bottom-actions sb-fs'>
							<Button
								customClass='sb-dashboard-btn'
								type='primary'
								size='small'
								icon='success'
								iconSize='11'
								text={ curRevF?.currentReviewForm?.new === true ? __( 'Add to Collection', 'reviews-feed' ) : __( 'Save and Exit', 'reviews-feed' ) }
								onClick={ () => {
									addUpdateReviewCollection()
								} }
							/>
						</div>
					}
				</div>
			</section>
		</section>
	)

}

export default FullScreenContainer;