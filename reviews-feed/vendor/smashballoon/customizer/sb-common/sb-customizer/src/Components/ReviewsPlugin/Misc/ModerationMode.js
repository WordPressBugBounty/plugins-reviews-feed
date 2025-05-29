import { __ } from '@wordpress/i18n'
import { useContext, useEffect, useMemo, useState } from 'react';
import SbUtils from '../../../Utils/SbUtils';
import Button from '../../Common/Button';
import Checkbox from '../../Common/Checkbox';
import Input from '../../Common/Input';
import FeedEditorContext from '../../Context/FeedEditorContext';

const ModerationMode = ( props ) => {

     const {
            sbCustomizer,
            editorFeedSettings,
            editorNotification,
            editorModerationReviews,
            editorModerationCurrentList,
            upsellModal,
            isPro
        } = useContext( FeedEditorContext );



    const [ showReviews, setShowReviews ] = useState( 'all' );

    const [ searchReviewsTerm, setSearchReviewsTerm ] = useState( '' );

    const [ filterDropDown, setFilterDropDown ] = useState( false );

    const [ filterRatings, setFilterRatings ] = useState( [] );
    const [ filterRatingsPending, setFilterRatingsPending ] = useState( [] );

    const [ filterProviders, setFilterProviders ] = useState( [] );
    const [ filterProvidersPending, setFilterProvidersPending ] = useState( [] );

    const [ filterStartDate, setFilterStartDate ] = useState( '' );
    const [ filterEndDate, setFilterEndDate ] = useState( '' );

    const [ filterStartDatePending, setFilterStartDatePending ] = useState( '' );
    const [ filterEndDatePending, setFilterEndDatePending ] = useState( '' );

    const [ numberOfPages, setNumberOfPages ] = useState( 0 );
    const [ currentPage, setCurrentPage ] = useState( 0 );
    let numberOfitems = 10;


    useEffect( () => {
        if( editorFeedSettings.feedSettings.moderationType === 'allow' ){
            editorModerationCurrentList.setModerationCurrentListSelected( editorFeedSettings.feedSettings.moderationAllowList )
        }
        if( editorFeedSettings.feedSettings.moderationType === 'block' ){
            editorModerationCurrentList.setModerationCurrentListSelected( editorFeedSettings.feedSettings.moderationBlockList )
        }
    }, [ editorFeedSettings.feedSettings.moderationType, editorFeedSettings.feedSettings.moderationAllowList , editorFeedSettings.feedSettings.moderationBlockList  ]);


    const [ reviewsList, setReviewsList ] = useState( editorModerationReviews?.moderationModeReviews );

    useEffect( () => {

        setReviewsList( editorModerationReviews?.moderationModeReviews )
        setNumberOfPages( Math.ceil( editorModerationReviews?.moderationModeReviews.length / numberOfitems ) )

    }, [ editorModerationReviews?.moderationModeReviews ]);

    const applyFilters = () => {
        setFilterRatings(filterRatingsPending);
        setFilterProviders(filterProvidersPending);
        setFilterStartDate(filterStartDatePending);
        setFilterEndDate(filterEndDatePending);
        SbUtils.applyNotification( {
            type : 'success',
            icon : 'success',
            text : __( 'Filters Applied', 'sb-customizer' )
        } , editorNotification );
    }

    const shownReviewsListMemo = useMemo( () => {
            if( editorModerationReviews?.moderationModeReviews !== null ){
                const filteredReviews = editorModerationReviews?.moderationModeReviews.filter( rev => {
                    let passRating = true,
                        passProvider = true,
                        passStartDate = true,
                        passEndDate = true,
                        passShowReviews = showReviews === 'all' ? true :  editorModerationCurrentList.moderationCurrentListSelected.includes( rev.review_id ),
                        passSearchReviewTerm = SbUtils.checkNotEmpty( searchReviewsTerm ) ? rev.text.toLowerCase().includes( searchReviewsTerm.toLowerCase() ) : true;

                    if( filterRatings.length > 0 ){
                        passRating = filterRatings.includes( rev.rating );
                    }
                    if( filterProviders.length > 0 ){
                        passProvider = filterProviders.includes( rev.provider.name );
                    }
                    if( SbUtils.checkNotEmpty( filterStartDate ) ){
                        passStartDate = rev.time >= SbUtils.convertDate( filterStartDate )
                    }
                    if( SbUtils.checkNotEmpty( filterEndDate ) ){
                        passEndDate = rev.time <= SbUtils.convertDate( filterEndDate )
                    }

                    if( passRating && passProvider && passStartDate && passEndDate && passShowReviews && passSearchReviewTerm){
                        return rev;
                    }


                } );
                setReviewsList( filteredReviews );
                return true;
            }
            return false;
        },
        [ showReviews, searchReviewsTerm, filterRatings, filterProviders, filterStartDate, filterEndDate ]
    );

    const checkFilterRating = ( rating ) => {
        const ratings = [...filterRatingsPending];
        if( !ratings.includes( rating ) ){
            ratings.push( rating )
        }else{
            ratings.splice( ratings.indexOf( rating ), 1 );
        }
        setFilterRatingsPending( ratings )
    }

    const checkFilterProvider = ( provider ) => {
        const providers = [...filterProvidersPending];
        if( !providers.includes( provider ) ){
            providers.push( provider )
        }else{
            providers.splice( providers.indexOf( provider ), 1 );
        }
        setFilterProvidersPending( providers )
    }

    const checkSelectCurrentReviewList = ( reviewID ) => {
        let currentSelectedList = [];
        if( reviewID === 'all' ){
            currentSelectedList =  editorModerationCurrentList.moderationCurrentListSelected.length === reviewsList.length ? [] : reviewsList.map( rv => rv.review_id )
            editorModerationCurrentList.setModerationCurrentListSelected( currentSelectedList );

        }else{
            currentSelectedList = [ ...editorModerationCurrentList.moderationCurrentListSelected ];
            if( !currentSelectedList.includes( reviewID ) ){
                currentSelectedList.push( reviewID )
            }else{
                currentSelectedList.splice( currentSelectedList.indexOf( reviewID ), 1 );
            }
            editorModerationCurrentList.setModerationCurrentListSelected( currentSelectedList )
        }
    }

    const isPostReviewSelected = ( reviewId ) => {
        return editorModerationCurrentList.moderationCurrentListSelected.includes( reviewId )
    }

    const openModerationUpsell = () => {
        SbUtils.openUpsellModal( 'moderationModal', upsellModal );
    }

    return (
        <div className='sb-moderation-ctn sb-fs'>

            <div className='sb-moderation-heading sb-fs sb-dark-text'>
                <h3 className='sb-h3'>{ __('Reviews', 'sb-customizer') }</h3>
                <div className='sb-moderation-pagination'>
                    <strong>{ editorModerationReviews?.moderationModeReviews.length } { __('Reviews', 'sb-customizer') }</strong>
                    <Button
                        icon='double-chevron-left'
                        type='secondary'
                        size='small'
                        iconSize='11'
                        boxshadow='false'
                        disabled={ currentPage === 0 || !isPro }
                        onClick={ () => {
                            setCurrentPage( 0 )
                        } }
                    />
                    <Button
                        icon='chevron-left'
                        type='secondary'
                        size='small'
                        iconSize='7'
                        boxshadow='false'
                        disabled={ currentPage === 0 || !isPro }
                        onClick={ () => {
                            if( currentPage !== 0 ){
                                setCurrentPage(currentPage - 1)
                            }
                        } }
                    />
                    <strong className='sb-moderation-pagination-num'>{ currentPage + 1 } of { numberOfPages }</strong>
                    <Button
                        icon='chevron-right'
                        type='secondary'
                        size='small'
                        iconSize='7'
                        boxshadow='false'
                        disabled={ currentPage === numberOfPages - 1   || !isPro }
                        onClick={ () => {
                            if( currentPage < numberOfPages - 1  ){
                                setCurrentPage(currentPage + 1)
                            }
                        } }
                    />
                    <Button
                        icon='double-chevron-right'
                        type='secondary'
                        size='small'
                        iconSize='11'
                        boxshadow='false'
                        disabled={ currentPage === numberOfPages - 1 || !isPro }
                        onClick={ () => {
                            setCurrentPage( numberOfPages - 1 )
                        } }
                    />
                </div>
            </div>

            <div className='sb-moderation-filter-ctn sb-fs sb-dark-text'>
                <div
                    className={ 'sb-moderation-button-filter sb-small-p ' + (filterDropDown ? 'sb-dark-text' : 'sb-light-text') }
                    onClick={ () => {
                        setFilterDropDown( !filterDropDown );
                    } }
                >
                    <span>{ __( 'Filter', 'sb-customizer' ) }</span>
                    { SbUtils.printIcon( 'carret-down', '', false, 11 ) }
                </div>
                <Input
                    size='medium'
                    customClass='sb-moderation-seacrh-input'
                    leadingIcon='search'
                    placeholder={ __( 'Search Reviews', 'sb-customizer' ) }
                    disableleading-brd='true'
                    value={ searchReviewsTerm }
                    onChange={ ( event ) => {
                        setSearchReviewsTerm( event.currentTarget.value )
                    } }
                />
                <div className='sb-moderation-action-btns'>
                    <Button
                        size='medium'
                        customClass={ showReviews === 'all' && 'sb-moderation-action-active'}
                        text={ __( 'Show All', 'sb-customizer' ) }
                        boxshadow='false'
                        onClick={ () => {
                            if( isPro ){
                                setShowReviews('all')
                            }else{
                                openModerationUpsell()
                            }
                        } }
                    />
                    <Button
                        size='medium'
                        customClass={ showReviews === 'selected' && 'sb-moderation-action-active' }
                        text={ __( 'Show Selected', 'sb-customizer' ) }
                        boxshadow='false'
                        onClick={ () => {
                            if( isPro ){
                                setShowReviews('selected')
                            }else{
                                openModerationUpsell()
                            }
                        } }
                    />
                </div>

            </div>
            {
                filterDropDown &&
                <div className='sb-moderation-filter-dropdown-ctn sb-fs'>

                    <div className='sb-moderation-dropdown-list sb-fs'>
                        <div className='sb-moderation-dropdown-item'>
                            <strong className='sb-fs'> { __( 'Rating', 'sb-customizer' ) } </strong>
                            <div className='sb-moderation-dropdown-content'>
                                {
                                    Array.from({ length: 5} , (ic, i) => {
                                        let rating = 5-i;
                                        return (
                                            <div
                                                className='sb-modetaion-checkbox-sec sb-fs'
                                                key={ rating }
                                                onClick={ ( event ) => {
                                                    event.stopPropagation()
                                                    event.preventDefault()
                                                    checkFilterRating( rating )
                                                } }
                                            >
                                                <Checkbox
                                                    value={ filterRatingsPending.includes( rating ) }
                                                    enabled={ true }
                                                    onChange={ () => {
                                                        checkFilterRating( rating )
                                                    } }
                                                />
                                                { SbUtils.printIcon( rating +'stars', 'sb-item-rating-icon') }
                                            </div>
                                        )
                                    })
                                }
                            </div>
                        </div>
                        <div className='sb-moderation-dropdown-item'>
                            <strong className='sb-fs'> { __( 'Provider', 'sb-customizer' ) } </strong>
                            <div className='sb-moderation-dropdown-content'>
                                {
                                    sbCustomizer.providers.map( provider => {
                                        return (
                                            <div
                                                className='sb-modetaion-checkbox-sec sb-fs'
                                                key={ provider.type }
                                                onClick={ ( event ) => {
                                                    event.stopPropagation()
                                                    event.preventDefault()
                                                    checkFilterProvider( provider.type )
                                                } }
                                            >
                                                <Checkbox
                                                    value={ filterProvidersPending.includes( provider.type ) }
                                                    enabled={ true }
                                                    onChange={ () => {
                                                        checkFilterProvider( provider.type )
                                                    } }
                                                />
                                                { provider.name }
                                            </div>
                                        )
                                    } )
                                }
                            </div>
                        </div>
                        <div className='sb-moderation-dropdown-item'>
                            <strong className='sb-fs'> { __( 'Date', 'sb-customizer' ) } </strong>
                            <div className='sb-moderation-dropdown-content'>
                                <Input
                                    type='text'
                                    size='medium'
                                    customClass='sb-fs sb-moderation-filter-date'
                                    placeholder={ __( 'From', 'sb-customizer' ) }
                                    value={ filterStartDatePending }
                                    onChange={ ( event ) => {
                                        setFilterStartDatePending( event.currentTarget.value )
                                    } }
                                    onFocus={ ( event ) => {
                                        event.currentTarget.type = 'date'
                                    } }
                                    onBlur={ ( event ) => {
                                        event.currentTarget.type = 'text'
                                    } }
                                />
                                <Input
                                    type='text'
                                    size='medium'
                                    customClass='sb-fs sb-moderation-filter-date'
                                    placeholder={ __( 'To', 'sb-customizer' ) }
                                    value={ filterEndDatePending }
                                    onChange={ ( event ) => {
                                        setFilterEndDatePending( event.currentTarget.value )
                                    } }
                                    onFocus={ ( event ) => {
                                        event.currentTarget.type = 'date'
                                    } }
                                    onBlur={ ( event ) => {
                                        event.currentTarget.type = 'text'
                                    } }
                                />
                            </div>
                        </div>
                    </div>
                    <div className='sb-moderation-dropdown-actions sb-fs'>
                        <Button
                            size='small'
                            type='primary'
                            text={ __( 'Apply', 'sb-customizer' ) }
                            onClick={ () => {
                                if( isPro ){
                                    applyFilters()
                                }else{
                                    openModerationUpsell()
                                }
                            } }
                        />
                        <Button
                            size='small'
                            type='seconday'
                            boxshadow='false'
                            text={ __( 'Cancel', 'sb-customizer' ) }
                            onClick={ ( ) => {
                                setFilterDropDown( false );
                            } }
                        />
                    </div>
                </div>
            }

            <div className='sb-moderation-list-ctn sb-fs'>
                <div className='sb-moderation-list-head sb-fs'>
                    <Checkbox
                        value={ reviewsList.length !== 0 && editorModerationCurrentList.moderationCurrentListSelected.length === reviewsList.length}
                        enabled={ true }
                        onChange={ ( ) => {
                            if( isPro ){
                                checkSelectCurrentReviewList( 'all')
                            }else{
                                openModerationUpsell()
                            }
                        } }
                    />
                    <span className='sb-text-small sb-dark2-text'> { __( 'Reviews', 'sb-customizer' ) } </span>
                </div>
                <div className='sb-moderation-list sb-fs'>
                    {
                        reviewsList !== null &&
                        reviewsList.slice( currentPage * numberOfitems, ( currentPage + 1 ) * numberOfitems ).map( post => {
                            return (
                                <div className='sb-moderation-rev-item sb-fs' key={ post.review_id }>
                                    { SbUtils.printIcon( post?.provider?.name.toLowerCase() + '-provider', 'sb-item-provider-icon' ) }
                                    <span className='sb-moderation-rev-item-date sb-dark2-text'>
                                            {window.date_i18n( 'd M Y, G:i', post.time )}
                                        </span>
                                    <div className='sb-moderation-rev-item-checkb'>
                                        <Checkbox
                                            value={ isPostReviewSelected( post.review_id ) }
                                            enabled={ true }
                                            onChange={ ( ) => {
                                                if( isPro ){
                                                    checkSelectCurrentReviewList( post.review_id )
                                                }else{
                                                    openModerationUpsell()
                                                }
                                            } }
                                        />
                                    </div>
                                    <div className='sb-moderation-rev-item-info'>
                                        <div className='sb-moderation-rev-item-info-top'>
                                            { SbUtils.printIcon( post?.rating + 'stars', 'sb-item-rating-icon') }
                                            <strong className='sb-text-small sb-bold'>{ post.text.slice(0, 40) + ( post.text.length > 40 ? '...' : '' )}</strong>
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
        </div>
    )
}

export default ModerationMode;