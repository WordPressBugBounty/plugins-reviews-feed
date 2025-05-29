import { useContext, useEffect, useState } from "react";
import SbUtils from "../../../../../Utils/SbUtils";
import Input from "../../../../Common/Input";
import { __ } from "@wordpress/i18n";
import Button from "../../../../Common/Button";

const CollectionReviewsList = () => {
	const currentContext = SbUtils.getCurrentContext();
    const {
        sbCustomizer,
        curCl,
        curRevF,
        slFullScreen,
        editorTopLoader,
        editorNotification,
        collectionP,
		collectionRv,
        getCollectionReviewsList,
        revSearch
    } = useContext( currentContext );

	const [ searchReviewsTerm, setSearchReviewsTerm ] = useState( '' );
    const [ filterDropDown, setFilterDropDown ] = useState( false );
	const [ numberOfPages, setNumberOfPages ] =  useState( 0 );
    let numberOfitems = 40;
    useEffect( () => {
        setNumberOfPages( Math.ceil( curCl?.currentCollection?.reviews_number / numberOfitems ) )
    }, [ curCl?.currentCollection?.reviews_number ]);



    const deleteCollectionReview = ( review ) => {
        const formData = {
            action : 'sbr_feed_saver_manager_delete_review_from_collection',
            provider : review?.provider?.name,
            provider_id : review.provider_id,
            review_id : review.review_id,
            page_number : collectionP.collectionReviewsPage
        },
        notificationsContent = {
            success : {
                icon : 'success',
                text : __( 'Review deleted from collection', 'sb-customizer' )
            }
        }
        SbUtils.ajaxPost(
            sbCustomizer.ajaxHandler,
            formData,
            ( data ) => { //Call Back Function
                const newNumber =  parseInt(isNaN(curCl?.currentCollection?.reviews_number) ? 0 : curCl?.currentCollection?.reviews_number) - 1
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
            },
            editorTopLoader,
            editorNotification,
            notificationsContent
        )
    }


    const searchReviewsCollectionList = () => {
        if (SbUtils.checkNotEmpty(searchReviewsTerm)) {
            const formData = {
                action : 'sbr_feed_saver_manager_advanced_search_reviews',
                provider_id : curCl.currentCollection.account_id,
                search_text : searchReviewsTerm
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
                revSearch.setShowReviews('search')
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

	return (
		<div className='sb-collection-list-ins sb-fs'>
			<div className='sb-moderation-filter-ctn sb-fs sb-dark-text'>
                <Input
                    size='medium'
                    customClass='sb-moderation-seacrh-input'
                    leadingIcon='search'
                    placeholder={ __( 'Search Reviews', 'reviews-feed' ) }
                    disableleading-brd='true'
                    value={ searchReviewsTerm }
                    onChange={ ( event ) => {
                        setSearchReviewsTerm( event.currentTarget.value )
                    }}
                    onKeyPress={ ( event ) => {
                        if (event.key === 'Enter') {
                            searchReviewsCollectionList()
                        }
                    } }
                />
                {
                    revSearch.showReviews === 'all' &&
                    <div className='sb-moderation-pagination'>
                        <strong>{curCl?.currentCollection?.reviews_number}  {curCl?.currentCollection?.reviews_number === 1 ? __('Review', 'reviews-feed') : __('Reviews', 'reviews-feed') }</strong>
                        <Button
                            icon='double-chevron-left'
                            type='secondary'
                            size='small'
                            iconSize='11'
                            boxshadow='false'
                            disabled={ collectionP.collectionReviewsPage === 1 }
                            onClick={ () => {
                                collectionP.setCollectionReviewsPage( 1 )
                                setTimeout(() => {
                                    getCollectionReviewsList(curCl?.currentCollection)
                                }, 100);
                            } }
                        />
                        <Button
                            icon='chevron-left'
                            type='secondary'
                            size='small'
                            iconSize='7'
                            boxshadow='false'
                            disabled={ collectionP.collectionReviewsPage === 1 }
                            onClick={ () => {
                                if( collectionP.collectionReviewsPage !== 1 ){
                                    collectionP.setCollectionReviewsPage(collectionP.collectionReviewsPage - 1)
                                    setTimeout(() => {
                                        getCollectionReviewsList(curCl?.currentCollection)
                                    }, 100);
                                }
                            } }
                        />
                        <strong className='sb-moderation-pagination-num'>{ collectionP.collectionReviewsPage } of { isNaN(numberOfPages) ? 1 : numberOfPages }</strong>
                        <Button
                            icon='chevron-right'
                            type='secondary'
                            size='small'
                            iconSize='7'
                            boxshadow='false'
                            disabled={ collectionP.collectionReviewsPage === numberOfPages}
                            onClick={ () => {
                                if( collectionP.collectionReviewsPage < numberOfPages  ){
                                    collectionP.setCollectionReviewsPage(collectionP.collectionReviewsPage + 1)
                                    setTimeout(() => {
                                        getCollectionReviewsList(curCl?.currentCollection)
                                    }, 100);
                                }
                            } }
                        />
                        <Button
                            icon='double-chevron-right'
                            type='secondary'
                            size='small'
                            iconSize='11'
                            boxshadow='false'
                            disabled={ collectionP.collectionReviewsPage === numberOfPages }
                            onClick={ () => {
                                collectionP.setCollectionReviewsPage( numberOfPages - 1 )
                                setTimeout(() => {
                                     getCollectionReviewsList(curCl?.currentCollection)
                                }, 100);
                            } }
                        />
                        <Button
                            icon='reset'
                            type='secondary'
                            size='small'
                            iconSize='14'
                            boxshadow='false'
							tooltip={ __( 'Refresh List', 'reviews-feed' ) }
                            onClick={ () => {
                                getCollectionReviewsList(curCl?.currentCollection)

                            } }
                        />
                    </div>
                }
                {
                    revSearch.showReviews === 'search' &&
                    <Button
                        type='destructive'
                        size='small'
                        iconSize='9'
                        boxshadow={false}
                        icon='close'
                        icon-position='right'
                        text={ __( 'Clear Search', 'reviews-feed' ).replace(/\s/g, '&nbsp;') }
                        onClick={ () => {
                            //collectionP.setCollectionReviewsPage( 1 )
                            setSearchReviewsTerm('')
                            revSearch.setShowReviews('all')
                            setTimeout(() => {
                                getCollectionReviewsList(curCl?.currentCollection)
                            }, 100);
                        } }
                        customClass='sb-coll-clear-search-btn'
                    />
                }
            </div>
            {
                collectionRv.collectionReviewsList !== null && collectionRv.collectionReviewsList.length > 0 &&
                <div className='sb-moderation-list-ctn sb-fs'>
                    <div className='sb-moderation-list-head sb-fs'>
                        <span className='sb-text-small sb-dark2-text'> { __( 'Reviews', 'reviews-feed' ) } </span>
                    </div>
                    <div className='sb-moderation-list sb-fs'>
                        {
                            collectionRv.collectionReviewsList !== null &&
                            collectionRv.collectionReviewsList.map( post => {
                                return (
                                    <div className='sb-moderation-rev-item sb-fs' key={ post.review_id }>
                                        { post?.provider?.name !== undefined && SbUtils.printIcon( post?.provider?.name.toLowerCase() + '-provider', 'sb-item-provider-icon', false, 12 ) }
                                        <span className='sb-moderation-rev-item-date sb-dark2-text'>
                                            {window.date_i18n( 'd M Y, G:i', post.time )}
                                        </span>
                                        <div className='sb-moderation-rev-item-info'>
                                            <div className='sb-moderation-rev-item-info-top'>
                                                { SbUtils.printIcon( post?.rating + 'stars', 'sb-item-rating-icon') }
                                                <strong className='sb-text-small sb-bold'>
                                                    { post?.title && post?.title.slice(0, 40) + ( post?.title.length > 40 ? '...' : '' )}
                                                    { (!post?.title && post?.text) && post.text.slice(0, 40) + ( post.text.length > 40 ? '...' : '' )}
                                                </strong>
                                            </div>
                                            <span className='sb-text-small sb-light-text2 sb-fs'>
                                                { post?.text }
                                            </span>
                                        </div>
                                        <div className='sb-collection-rev-actions'>
                                            <div className='sb-feed-item-btns'>
                                                    <div className='sb-relative'>
                                                        <Button
                                                            type='secondary'
                                                            size='small'
                                                            iconSize='14'
                                                            boxshadow={false}
                                                            icon='pen'
                                                            tooltip={ __( 'Edit', 'reviews-feed' ) }
                                                            onClick={ () => {
                                                                curRevF.setCurrentReviewForm(post)
                                                                slFullScreen.setCollectionFullScreen(true)
                                                            } }
                                                            customClass='sb-feed-action-btn sb-feed-edit-btn'
                                                        />
                                                    </div>
                                                    <div className='sb-relative'>
                                                        <Button
                                                            type='secondary'
                                                            size='small'
                                                            iconSize='11'
                                                            boxshadow={false}
                                                            icon='trash'
                                                            tooltip={ __( 'Delete', 'reviews-feed' ) }
                                                            onClick={ () => {
                                                             deleteCollectionReview(post)
                                                            } }
                                                            customClass='sb-feed-action-btn sb-feed-delete-btn'
                                                        />
                                                    </div>
                                                </div>
                                        </div>
                                    </div>
                                )
                            } )
                        }
                    </div>
                </div>
            }
            {
                (collectionRv.collectionReviewsList === null || collectionRv.collectionReviewsList.length === 0) && revSearch.showReviews === 'search' &&
                <>
                    <p className='sb-fs'>
                        <strong>{__('No reviews found!', 'reviews-feed')}</strong>
                    </p>
                </>
            }
		</div>
	)
}
export default CollectionReviewsList;