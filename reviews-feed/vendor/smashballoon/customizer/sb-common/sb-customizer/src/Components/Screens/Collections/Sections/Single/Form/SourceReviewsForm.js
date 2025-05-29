import { __ } from "@wordpress/i18n";
import { useContext, useState } from "react";
import SbUtils from "../../../../../../Utils/SbUtils";
import Checkbox from "../../../../../Common/Checkbox";
import Input from "../../../../../Common/Input";
import Button from "../../../../../Common/Button";

const SourceReviewsForm = () => {
	const currentContext = SbUtils.getCurrentContext();
    const {
		sbCustomizer,
		editorTopLoader,
		editorNotification,
		crFormSr,
		reviewsForm,
		getCollectionReviewsList,
		sourcesRP,
		curSr
	} = useContext( currentContext );

	const [ searchSourceReviewsTerm, setSearchSourceReviewsTerm ] = useState( '' );
	const [ isSearchActive, setIsSearchActive ] = useState( false );

	const isSourceEmpty = ( crFormSr?.crFormReviewsList.length > 0 && crFormSr?.crFormReviewsList !== null ) || isSearchActive;

	const isReviewFormSelected = ( reviewID ) => {
		return reviewsForm?.selectedReviews.includes( reviewID )
    }
	const selectFormReview  = ( reviewID ) => {
		let currentSelectedList = [];
		currentSelectedList = [ ...reviewsForm?.selectedReviews ];
        if( !currentSelectedList.includes( reviewID ) ){
        	currentSelectedList.push( reviewID )
        }else{
        	currentSelectedList.splice( currentSelectedList.indexOf( reviewID ), 1 );
        }
        reviewsForm?.setSelectedReviews( currentSelectedList )
	}

	const loadMoreSourceReviews = () => {
		sourcesRP.setSourcesFormReviewsPage( sourcesRP.sourcesFormReviewsPage + 1 )
		setTimeout(() => {
			getCollectionReviewsList(curSr?.currentSourceForm, 'form')
    	}, 100);
	}



	const searchReviewsCollectionList = () => {
        if (SbUtils.checkNotEmpty(searchSourceReviewsTerm)) {
            const formData = {
                action : 'sbr_feed_saver_manager_advanced_search_reviews',
                provider_id : curSr?.currentSourceForm.account_id,
                search_text : searchSourceReviewsTerm
            },
            notificationsContent = {
                success : {
                    icon : 'success',
                    text : __( 'Search list updated', 'sb-customizer' )
                }
            }
            SbUtils.ajaxPost(
            sbCustomizer.ajaxHandler,
            formData,
            ( data ) => { //Call Back Function
                setIsSearchActive(true)
                if (data?.postsList) {
                    let parsedPostList = data?.postsList.map(element => {
                        element = JSON.parse(element.json_data)
                        return element;
                    });
                    crFormSr?.setCrFormReviewsList(parsedPostList);
                }
            },
            editorTopLoader,
            editorNotification,
            notificationsContent
        )

        }

    }
	return (
		<>
			{
				isSourceEmpty &&
				<div className='sb-sourceform-rev-list sb-fs'>
					{
						reviewsForm?.selectedReviews !== null && reviewsForm?.selectedReviews.length > 0 &&
						<div className='sb-sourceform-rev-selected sb-fs'>
							{reviewsForm?.selectedReviews.length} {reviewsForm?.selectedReviews.length === 1 ? __('Review Selected', 'reviews-feed') : __('Reviews Selected', 'reviews-feed')}
						</div>
					}
					<div className='sb-collection-source-search sb-fs'>
						<Input
							size='medium'
							customClass='sb-moderation-seacrh-input'
							leadingIcon='search'
							placeholder={ __( 'Search Source Reviews', 'reviews-feed' ) }
							disableleading-brd='true'
							value={ searchSourceReviewsTerm }
							onChange={ ( event ) => {
								setSearchSourceReviewsTerm( event.currentTarget.value )
							}}
							onKeyPress={ ( event ) => {
								if (event.key === 'Enter') {
									searchReviewsCollectionList()
								}
							} }
						/>
						{
							isSearchActive &&
							<Button
								type='destructive'
								size='small'
								iconSize='9'
								boxshadow={false}
								icon='close'
								icon-position='right'
								text={ __( 'Clear Search', 'reviews-feed' ).replace(/\s/g, '&nbsp;') }
								onClick={ () => {
									setSearchSourceReviewsTerm('')
									setIsSearchActive(false)
									sourcesRP.setSourcesFormReviewsPage(1)
									setTimeout(() => {
										getCollectionReviewsList(curSr?.currentSourceForm, 'form', true)
									}, 100);
								} }
								customClass='sb-coll-clear-search-btn'
							/>
						}
					</div>
					{
						(crFormSr?.crFormReviewsList === null || crFormSr?.crFormReviewsList.length === 0) && isSearchActive &&
						<>
							<p className='sb-source-coll-no-rev sb-fs'>
								<strong>{__('No reviews found!', 'reviews-feed')}</strong>
							</p>
						</>
					}
					{
						(crFormSr?.crFormReviewsList !== null && crFormSr?.crFormReviewsList.length > 0) &&
						<div className='sb-moderation-list-ctn sb-fs'>
							<div className='sb-moderation-list sb-fs'>
								{
									crFormSr?.crFormReviewsList.map( (post,index) => {
										return (
											<div className='sb-moderation-rev-item sb-fs' key={ index }>
												<div className='sb-moderation-rev-item-checkb'>
													<Checkbox
														value={ isReviewFormSelected( post.review_id ) }
														enabled={ true }
														onChange={ ( ) => {
															selectFormReview( post.review_id )
														} }
													/>
												</div>
												<div className='sb-moderation-rev-item-info'>
													<div className='sb-moderation-rev-item-info-top'>
														{ SbUtils.printIcon( post?.rating + 'stars', 'sb-item-rating-icon') }
														<strong className='sb-text-small sb-bold'>
															{ post?.title && post?.title.slice(0, 40) + ( post?.title.length > 40 ? '...' : '' )}
															{ (!post?.title && post?.text) && post.text.slice(0, 40) + ( post.text.length > 40 ? '...' : '' )}
														</strong>
													</div>
													<span className='sb-text-small sb-light-text2 sb-fs'>
														{ post.text }
													</span>
												</div>
											</div>
										)
									} )
								}
							</div>
						</div>
					}
					{
						sourcesRP.sourcesFormReviewsPage !== null && !isSearchActive &&
						<div
							className='sb-sourceform-rev-list-load sb-fs'
							onClick={() => {
								loadMoreSourceReviews()
							}}
						>
							{__('Load More Reviews', 'reviews-feed')}
							{ SbUtils.printIcon('chevron-bottom', 'sb-sourceform-rev-list-load-icon', false, 13) }
						</div>
					}
				</div>
			}
			{
				!isSourceEmpty && !isSearchActive &&
				<div className='sb-sourceform-center sb-fs sb-standard-p sb-bold'>
					{__('There are no reviews in this source, please select another source!', 'reviews-feed')}
				</div>
			}
		</>

	)


}

export default SourceReviewsForm;