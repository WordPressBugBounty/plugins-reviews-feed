import { __ } from "@wordpress/i18n";
import Button from "../../../Common/Button";
import SbUtils from "../../../../Utils/SbUtils";
import { useContext, useMemo, useState } from "react";
import ModalContainer from "../../../Global/ModalContainer";
import CreateCollectionModal from "../../../Modals/CreateCollectionModal";
import CollectionEmpty from "./Single/CollectionEmpty";

const CollectionsList = () => {
    const currentContext = SbUtils.getCurrentContext();
    const {
        sbCustomizer,
        slSection,
        sources,
        curCl,
        editorTopLoader,
        editorNotification,
        collectionP,
        getCollectionReviewsList,
        editorConfirmDialog,
        currentForms
    } = useContext( currentContext );

    const [ collectionNamePopup, setCollectionNamePopup ] = useState( false );
    const [ collectionInfoPopup, setCollectionInfoPopup ] = useState( false );


    const [ collectionsList, setCollectionsList ] = useState( sources?.sourcesList.filter(s => s.provider === 'collection') );
    const collections =  useMemo(
        () => ({ collectionsList, setCollectionsList }),
		[collectionsList, setCollectionsList]
    );




    const createNewCollection  = (collectionName = '') => {
        if (SbUtils.checkNotEmpty(collectionName)) {
            const formData = {
                action : 'sbr_feed_saver_manager_create_new_collection',
                collection_name : collectionName
            },
            notificationsContent = {
                success : {
                    icon : 'success',
                    text : __( 'New Collection created successfully', 'reviews-feed' )
                }
            }

            SbUtils.ajaxPost(
                sbCustomizer.ajaxHandler,
                formData,
                ( data ) => { //Call Back Function
                    setCollectionNamePopup(false)
                    sources.setSourcesList(data?.sourcesList)
                    curCl.setCurrentCollection(data?.newCollection)
                    setTimeout(function(){
                        slSection.setCollectionSectionActive('single')
                    }, 200)
                },
                editorTopLoader,
                editorNotification,
                notificationsContent
            )
        }
    }


    const deleteCollection = ( collection ) => {
        const confirmDialogInfo = {
                active : true,
                heading : `${ __('Delete ', 'reviews-feed') + '"'+collection.name +'"?'  }`,
                description : __( 'By deleting this collection, all the reviews created and added for it will be deleted too', 'reviews-feed' ),
                confirm : {}
        },
        notificationsContent = {
            success : {
                icon : 'success',
                text : __( 'Collection Deleted!', 'reviews-feed' )
            }
        };

        confirmDialogInfo.confirm.onConfirm = () => {
            const formData = {
                action : 'sbr_feed_saver_manager_delete_source',
                sourceID : collection.id,
                sourceAccountID : collection.account_id,
                sourceProvider : collection.provider,
                isCollection : true
            }
            SbUtils.ajaxPost(
                sbCustomizer.ajaxHandler,
                formData,
                ( data ) => { //Call Back Function
                    sources.setSourcesList( data.sourcesList )
                    collections.setCollectionsList(data.sourcesList.filter(s => s.provider === 'collection') )
                },
                editorTopLoader,
                editorNotification,
                notificationsContent
            )
        }
        editorConfirmDialog.setConfirmDialog( confirmDialogInfo )

    }


    const duplicateCollection = ( collection ) => {

         const confirmDialogInfo = {
                active : true,
                heading : `${ __('Duplicate ', 'reviews-feed') + '"'+collection.name +'"?'  }`,
                description : __( 'By duplicating this collection, all the reviews created and added to this collection will be duplicated too!', 'reviews-feed' ),
                confirm : {
                    type : 'primary',
                    text : __('Duplicate Collection ', 'reviews-feed')
                }
        },
        notificationsContent = {
            success : {
                icon : 'success',
                text : __( 'Collection Duplicated!', 'reviews-feed' )
            }
        };
        confirmDialogInfo.confirm.onConfirm = () => {
            const formData = {
                action : 'sbr_feed_saver_manager_duplicate_collection',
                collection_id : collection.account_id
            }
            SbUtils.ajaxPost(
                sbCustomizer.ajaxHandler,
                formData,
                ( data ) => { //Call Back Function
                    if (data?.sourcesList ){
                        sources.setSourcesList( data.sourcesList )
                        collections.setCollectionsList(data.sourcesList.filter(s => s.provider === 'collection') )
                    }
                },
                editorTopLoader,
                editorNotification,
                notificationsContent
            )
        }
        editorConfirmDialog.setConfirmDialog( confirmDialogInfo )

    }

	return (
		<>
			<div className='sb-dashboard-heading sb-fs'>
                <h2 className='sb-h2'>{ __( 'All Collections', 'reviews-feed' ) }</h2>
                <div className='sb-dashboard-action-btns'>
                    <Button
                        customClass='sb-dashboard-btn'
                        type='primary'
                        size='small'
                        icon='plus'
                        iconSize='11'
                        text={ __( 'Add New', 'reviews-feed' ) }
                        onClick={ () => {
                            setCollectionNamePopup(true)
                        } }
                    />
                    {
                        collections?.collectionsList.length > 0 &&
                        <span
                            className='sb-fs sb-coll-link sb-light-text2 sb-bold'
                            onClick={() => {
                                setCollectionInfoPopup(true)
                            }}
                        >
                            {SbUtils.printIcon('help', '', false, 16)}
                            {__('What are collections?', 'reviews-feed')}
                        </span>
                    }
                </div>
            </div>
            {
                collections.collectionsList.length > 0 &&
                <div className='sb-collections-ctn sb-fs'>
                    {
                        collections?.collectionsList.map( (collection, ckey) => {
                           return (
                                <div className='sb-collection-item' key={ckey}>
                                    <div className='sb-collection-item-icon'>{ SbUtils.printIcon( 'collection-provider', false, false, 32  ) }</div>
                                    <div className='sb-collection-item-info'>
                                        <div className='sb-collection-item-text'>
                                            <strong className='sb-collection-item-name sb-standard-p'>{collection.name}</strong>
                                            <span className='sb-collection-item-num sb-small-p'>{collection.reviews_number} {collection.reviews_number === 1 ? __( 'Review', 'reviews-feed' ) : __( 'Reviews', 'reviews-feed' )}</span>
                                        </div>
                                        <div className='sb-collection-item-actions sb-tr-2'>
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
                                                            curCl.setCurrentCollection(collection)
                                    						currentForms.setCurrentConnectedForms(SbUtils.getCollectionConnectedForms(collection))
                                                            collectionP.setCollectionReviewsPage(1)
                                                            getCollectionReviewsList(collection)
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
                                                        tooltip={ __( 'Duplicate', 'reviews-feed' ) }
                                                        onClick={ () => {
                                                            duplicateCollection(collection)
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
                                                        tooltip={ __( 'Delete', 'reviews-feed' ) }
                                                        onClick={ () => {
                                                            deleteCollection(collection)
                                                        } }
                                                        customClass='sb-feed-action-btn sb-feed-delete-btn'
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                           )
                        })
                    }
                </div>
            }

            {
                collections.collectionsList.length === 0 &&
                <div className='sb-collections-empty-ctn sb-fs'>
                    <div className='sb-collections-empty-insider'>

                        <div className='sb-collections-empty-icon'>
                            <img src={ window.sb_customizer.assetsURL + 'sb-customizer/assets/images/collections-splash.svg' } alt={__( 'Empty Collections Icon', 'reviews-feed' )} />
                        </div>
                        <div className='sb-collections-empty-text'>
                            <h4 className='sb-h4'>{ __('Showcase your best reviews with collections', 'reviews-feed') }</h4>
                            <p className='sb-standard-p sb-light-text sb-fs'>{ __('Create collections of handpicked reviews to showcase them on your website, reviews page or product page', 'reviews-feed') }</p>
                        </div>

                    </div>
                </div>
            }
            {
                collectionNamePopup&&
                <ModalContainer
                    size='small'
                    closebutton={true}
                    onClose={ () => {
                        setCollectionNamePopup(false)
                    } }
                >
                    <CreateCollectionModal
                        createNewCollection={(collectionName) => {
                            createNewCollection(collectionName)
                        }}
                        onCancel={ () => {
                            setCollectionNamePopup(false)
                        } }
                    />
                </ModalContainer>
            }
            {
                collectionInfoPopup &&
                <ModalContainer
                    size='medium'
                    type='collections-info'
                    closebutton={true}
                    onClose={ () => {
                        setCollectionInfoPopup(false)
                    } }
                >
                    <>
                        <div className='sb-collections-inf-modal-top sb-fs'>
                            <h4 className='sb-h4'>{ __('What are collections?', 'reviews-feed') }</h4>
                        </div>
                        <CollectionEmpty
                            modal={true}
                        />
                        <div className='sb-collections-inf-modal-bottom sb-fs'>
                            <Button
                                type='primary'
                                size='small'
                                icon='success'
                                iconSize='11'
                                text={ __( 'Okay, got it', 'reviews-feed' ) }
                                onClick={ () => {
                                    setCollectionInfoPopup(false)
                                } }
                            />
                        </div>
                    </>
                </ModalContainer>
            }
		</>
	)

}

export default CollectionsList;
