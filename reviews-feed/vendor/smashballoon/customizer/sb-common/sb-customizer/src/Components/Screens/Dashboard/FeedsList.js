import { useContext, useEffect, useRef, useState } from 'react'
import SbUtils from '../../../Utils/SbUtils'
import { __ } from '@wordpress/i18n'
import Button from '../../Common/Button';
import Select from '../../Common/Select';
import DashboardScreenContext from '../../Context/DashboardScreenContext';
import Checkbox from '../../Common/Checkbox';
import BottomUpsellBanner from '../../Global/BottomUpsellBanner';

const FeedsList = () => {

    const sbCustomizer = window.sb_customizer;
    const {
        feeds,
        editorTopLoader,
        editorNotification,
        editorConfirmDialog,
        feedsNumber
    } = useContext( DashboardScreenContext );

    const [ selectedFeeds, setSelectedFeeds ] = useState( [] );
    const [ bulkAction, setBulkAction ] = useState( '' );
    const [ currentPage, setCurrentPage ] = useState(1);
    const currentPageRef = useRef( currentPage )
    useEffect(() => {
        currentPageRef.current = currentPage
    }, [ currentPage ]);


    const bulkActionsList = {
        ''  : __( 'Bulk Actions', 'sb-customizer' ),
        'delete'  : __( 'Delete', 'sb-customizer' )
    };



    const applyBulkAction = () => {
        if( bulkAction === 'delete' && selectedFeeds.length > 0){
            deleteFeed( null, false ); //This will delete all selected feeds
        }
    }

    const duplicateFeed = ( feedId ) => {
        const formData = {
            action : 'sbr_feed_saver_manager_duplicate_feed',
            feed_id : feedId
        },
        notificationsContent = {
            success : {
                icon : 'success',
                text : __( 'Feed Duplicated', 'sb-customizer' )
            }
        }
        SbUtils.ajaxPost(
            sbCustomizer.ajaxHandler,
            formData,
            ( data ) => { //Call Back Function
                feeds.setFeedsList( data.feedsList );
                setSelectedFeeds( [] );
                feedsNumber.setFeedsCount(data.feedsCount)
            },
            editorTopLoader,
            editorNotification,
            notificationsContent
        )
    }


    //Confirm Action to delete feed(s)
    const deleteFeedConfirm = ( feedId, singleFeed = true  ) => {
        let feedsToDelete = [];
        if( singleFeed === true ){
            feedsToDelete.push(feedId);
        }else{
            feedsToDelete = selectedFeeds;
        }

        const formData = {
            action : 'sbr_feed_saver_manager_delete_feeds',
            feeds_ids : JSON.stringify( feedsToDelete )
        },
        notificationsContent = {
            success : {
                icon : 'success',
                text : __( 'Feed Deleted', 'sb-customizer' )
            }
        }
        SbUtils.ajaxPost(
            sbCustomizer.ajaxHandler,
            formData,
            ( data ) => { //Call Back Function
                feeds.setFeedsList( data.feedsList );
                setSelectedFeeds( [] );
                feedsNumber.setFeedsCount(data.feedsCount)
            },
            editorTopLoader,
            editorNotification,
            notificationsContent
        )

    }

    //Open Dialog Box to delete feeds
    const deleteFeed = ( feed, singleFeed = true ) => {
        const deleteHeading = singleFeed ? __( 'Delete "', 'sb-customizer' ) + feed.feed_name +'"?'  : __( 'Delete Feeds?', 'sb-customizer' );
        const confirmDialogInfo = {
            active : true,
            heading : deleteHeading,
            description : __( 'You are going to delete this feed. You will lose all the settings. Are you sure you want to continue?', 'sb-customizer' ),
            confirm : {}
        }
        confirmDialogInfo.confirm.onConfirm = () => {
            deleteFeedConfirm( singleFeed ? feed.id : null, singleFeed )
        }
        editorConfirmDialog.setConfirmDialog( confirmDialogInfo )


    }

    const selectFeed = ( feedId ) => {
        const sFeeds = Object.values( selectedFeeds ),
              feedsList = Object.values( feeds.feedsList );
        if( feedId === 'all' ){
            if( sFeeds.length === feedsList.length ){
                setSelectedFeeds( [] );
            }else{
                let feeds = [];
                feedsList.forEach(feed => {
                    feeds.push( feed.id )
                })
                setSelectedFeeds( feeds )
            }
        }else{
            if( !sFeeds.includes( feedId ) ){
                sFeeds.push(feedId)
            }else{
                sFeeds.splice( sFeeds.indexOf( feedId ), 1 );
            }
            setSelectedFeeds(sFeeds)
        }
    }

    const sourcesListNames = ( sourcesList ) => {
        let printedNames = [];
        const names = sourcesList.filter( source => {
            if(!printedNames.includes(source.provider)){
                printedNames.push(source.provider);
            }
        } )
        return printedNames.join(', ')
    }

    const feedPaginationAction = ( type ) => {
        setCurrentPage( type === 'next' ? currentPage+1 : currentPage-1 )

        const  notificationsContent = {
            success : {
                icon : 'success',
                text : __( 'Feed List Updated', 'sb-customizer' )
            }
        }
        setTimeout(() => {
            SbUtils.ajaxPost(
                sbCustomizer.ajaxHandler,
                {
                    action : 'sbr_feed_saver_manager_get_feed_list_page',
                    page : currentPageRef.current
                },
                ( data ) => { //Call Back Function
                    feeds.setFeedsList( data );
                },
                editorTopLoader,
                editorNotification,
                notificationsContent
            )
        }, 50);


    }




    return (
        <div className='sb-feedslist-ctn sb-fs'>
            <div className='sb-feedslist-top'>
                <div className='sb-feedslist-bulk-ctn'>
                    <Select
                        size='small'
                        customClass='sb-blulk-select'
                        onChange={ ( event ) => {
                            setBulkAction( event.currentTarget.value );
                        } }
                    >
                        {
                            Object.keys(bulkActionsList).map( action => {
                                return (
                                    <option key={ action } value={ action } >{ bulkActionsList[action] }</option>
                                )
                            })
                        }
                    </Select>
                    <Button
                        type='secondary'
                        size='small'
                        boxshadow='false'
                        text={ __( 'Apply', 'sb-customizer' ) }
                        onClick={ () => {
                            applyBulkAction()
                        } }
                    />
                </div>
                <div className='sb-feedslist-pagination'>
                    <span className='sb-text-tiny'>{ feedsNumber.feedsCount } { __( 'Items', 'sb-customizer' ) }</span>
                    {
                        feedsNumber.feedsCount > 20 &&
                        <div className='sb-feedslist-pagination-btns'>
                            <Button
                                type='secondary'
                                size='small'
                                icon='chevron-left'
                                iconSize='7'
                                boxshadow='false'
                                disabled={ currentPage === 1 }
                                onClick={ () => {
                                    if( currentPage !== 1 ){
                                        feedPaginationAction( 'prev' )
                                    }
                                } }
                            />
                            <span>{currentPage + ' ' + __( 'of', 'sb-customizer' ) + ' ' + Math.ceil( feedsNumber.feedsCount / 20  )} </span>
                            <Button
                                type='secondary'
                                size='small'
                                icon='chevron-right'
                                iconSize='7'
                                boxshadow='false'
                                disabled={ currentPage >= Math.ceil( feedsNumber.feedsCount / 20 ) }
                                onClick={ () => {
                                    if( currentPage < Math.ceil( feedsNumber.feedsCount / 20 ) ){
                                        feedPaginationAction( 'next' )
                                    }
                                } }
                            />
                        </div>
                    }

                </div>
            </div>

            <div className='sb-feedslist-table-wrap sb-fs'>
                <table className='sb-feedslist-table  sb-text-tiny'>

                    <thead className='sb-feedslist-thtf sb-feedslist-thead sb-dark2-text'>
                        <tr>
                            <th>
                                <Checkbox
                                    value={ selectedFeeds.length === feeds.feedsList.length }
                                    enabled={ true }
                                    onChange={ () => {
                                        selectFeed( 'all' )
                                    } }
                                />
                            </th>
                            <th>
                                <span>{ __( 'Name', 'sb-customizer' ) }</span>
                            </th>
                            <th>
                                <span>{ __( 'Shortcode', 'sb-customizer' ) }</span>
                            </th>
                            <th>
                                <span>{ __( 'Instances', 'sb-customizer' ) }</span>
                            </th>
                            <th className='cff-fd-lst-act-th'>
                                 <span>{ __( 'Actions', 'sb-customizer' ) }</span>
                            </th>
                        </tr>
                    </thead>

                    <tbody  className='sb-feedslist-tbody'>
                        {
                            feeds.feedsList.map( ( feed, feedIn ) => {
                                let instancesL = feed.location_summary.length;
                                return (
                                    <tr key={feedIn}>
                                        <td>
                                            <Checkbox
                                                value={ selectedFeeds.includes( feed.id ) }
                                                enabled={ true }
                                                onChange={ () => {
                                                    selectFeed( feed.id )
                                                } }
                                            />
                                        </td>
                                        <td className='sb-feedslist-name-ctn'>
                                            <a className='sb-text-small' href={ `${sbCustomizer.builderUrl}&feed_id=${feed.id}` }>{ feed.feed_name }</a>
                                            <span>{sourcesListNames( feed?.sourcesList )}</span>
                                        </td>
                                        <td>
                                            <span>[reviews-feed feed={ feed.id }]</span>
                                            <span className='sb-relative'>
                                                <Button
                                                    type='secondary'
                                                    size='small'
                                                    boxshadow={false}
                                                    icon='copy'
                                                    tooltip={ __( 'Copy Shortcode', 'sb-customizer' ) }
                                                    customClass='sb-feed-copy-btn'
                                                    onClick={ () => {
                                                        SbUtils.copyToClipBoard( `[reviews-feed feed=${ feed.id }]`, editorNotification )
                                                    }}
                                                />
                                            </span>
                                        </td>
                                        <td>
                                            { __( 'Used in ', 'sb-customizer' ) }
                                            <span className={ ( instancesL > 0 && 'sb-bold sb-item-instances-link sb-relative' ) || ''}>
                                                { instancesL } { instancesL === 1 ? __( 'place', 'sb-customizer' ) : __( 'places', 'sb-customizer' ) }
                                                {
                                                    instancesL > 0 &&
                                                    SbUtils.printTooltip( 'Click to view Instances' )
                                                }
                                            </span>
                                        </td>
                                        <td className='sb-feed-item-actions'>
                                            <div className='sb-feed-item-btns'>
                                                <div className='sb-relative'>
                                                    <Button
                                                        type='secondary'
                                                        size='small'
                                                        iconSize='14'
                                                        boxshadow={false}
                                                        icon='pen'
                                                        tooltip={ __( 'Edit', 'sb-customizer' ) }
                                                        onClick={ () => {
                                                            window.location.href = `${sbCustomizer.builderUrl}&feed_id=${feed.id}`
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
                                                        icon='duplicate'
                                                        tooltip={ __( 'Duplicate', 'sb-customizer' ) }
                                                        onClick={ () => {
                                                            duplicateFeed( feed.id )
                                                        } }
                                                        customClass='sb-feed-action-btn sb-feed-duplicate-btn'
                                                    />
                                                </div>
                                                <div className='sb-relative'>
                                                    <Button
                                                        type='secondary'
                                                        size='small'
                                                        iconSize='11'
                                                        boxshadow={false}
                                                        icon='trash'
                                                        tooltip={ __( 'Delete', 'sb-customizer' ) }
                                                        onClick={ () => {
                                                            deleteFeed( feed )
                                                        } }
                                                        customClass='sb-feed-action-btn sb-feed-delete-btn'
                                                    />
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                )
                            } )
                        }
                    </tbody>

                    <tfoot className='sb-feedslist-thtf sb-feedslist-tfoot sb-dark2-text'>
                        <tr>
                            <th>
                                <Checkbox
                                    value={ selectedFeeds.length === feeds.feedsList.length }
                                    enabled={ true }
                                    onChange={ () => {
                                        selectFeed( 'all' )
                                    } }
                                />
                            </th>
                            <th>
                                <span className='sb-text-tiny sb-dark2-text'>{ __( 'Name', 'sb-customizer' ) }</span>
                            </th>
                            <th>
                                <span className='sb-text-tiny sb-dark2-text'>{ __( 'Shortcode', 'sb-customizer' ) }</span>
                            </th>
                            <th>
                                <span className='sb-text-tiny sb-dark2-text'>{ __( 'Instances', 'sb-customizer' ) }</span>
                            </th>
                            <th className='cff-fd-lst-act-th'>
                                <span className='sb-text-tiny sb-dark2-text'>{ __( 'Actions', 'sb-customizer' ) }</span>
                            </th>
                        </tr>
                    </tfoot>

                </table>

                <BottomUpsellBanner />

            </div>
        </div>
    )
}

export default FeedsList;