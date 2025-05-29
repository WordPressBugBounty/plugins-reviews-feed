import Header from '../Global/Header'
import { __ } from '@wordpress/i18n'
import CollectionsScreenContext from '../Context/CollectionsScreenContext'
import FreeCollectionsPage from './Collections/FreeCollectionsPage'
import ProCollectionsPage from './Collections/ProCollectionsPage'
import { useEffect, useMemo, useRef, useState } from 'react'
import SbUtils from '../../Utils/SbUtils'


const CollectionsScreen = ( { sbCustomizer, editorTopLoader, editorNotification, editorConfirmDialog, isPro, upsellModal, apis, noticeBar, noticeBarMemo, sources, allowedTiers, sourcesCount} ) => {

    const [ collectionSectionActive,  setCollectionSectionActive ] = useState('list');
    const slSection = useMemo(
        () => ({ collectionSectionActive,  setCollectionSectionActive }),
		[collectionSectionActive,  setCollectionSectionActive]
    );

	const [ collectionFullScreen, setCollectionFullScreen ] = useState( false );
    const slFullScreen = useMemo(
        () => ({ collectionFullScreen, setCollectionFullScreen }),
		[collectionFullScreen, setCollectionFullScreen]
    );

    const [ currentCollection,  setCurrentCollection ] = useState(null);
    const curCl = useMemo(
        () => ({ currentCollection,  setCurrentCollection  }),
		[currentCollection,  setCurrentCollection]
    );

    const [ currentReviewForm, setCurrentReviewForm ] = useState(null);
    const curRevF = useMemo(
        () => ({ currentReviewForm, setCurrentReviewForm }),
		[currentReviewForm, setCurrentReviewForm]
    );

    const [ collectionReviewsList, setCollectionReviewsList ] = useState([]);
    const collectionRv =  useMemo(
        () => ({ collectionReviewsList, setCollectionReviewsList  }),
		[collectionReviewsList, setCollectionReviewsList ]
    );

    //Current COllection Reviews List in the Collectios Page
    const [ collectionReviewsPage, setCollectionReviewsPage ] = useState(1);
    const collectionP =  useMemo(
        () => ({ collectionReviewsPage, setCollectionReviewsPage }),
		[collectionReviewsPage, setCollectionReviewsPage]
    );
    const currentCollPageRef = useRef( collectionReviewsPage )
    useEffect(() => {
        currentCollPageRef.current = collectionReviewsPage
    }, [ collectionReviewsPage ]);

    //Reviews List in the Full Screen Form when adding from an existing Source
    const [ sourcesFormReviewsPage, setSourcesFormReviewsPage ] = useState(1);
    const sourcesRP =  useMemo(
        () => ({ sourcesFormReviewsPage, setSourcesFormReviewsPage }),
		[sourcesFormReviewsPage, setSourcesFormReviewsPage]
    );
    const currentSourceFormPageRef = useRef( sourcesFormReviewsPage )
    useEffect(() => {
        currentSourceFormPageRef.current = sourcesFormReviewsPage
    }, [ sourcesFormReviewsPage ]);


    const [ crFormReviewsList, setCrFormReviewsList ] = useState(null);
    const crFormSr =  useMemo(
        () => ({ crFormReviewsList, setCrFormReviewsList  }),
		[crFormReviewsList, setCrFormReviewsList ]
    );

    const [ currentSourceForm,  setCurrentSourceForm ] = useState(null);
    const curSr = useMemo(
        () => ({ currentSourceForm,  setCurrentSourceForm  }),
		[currentSourceForm,  setCurrentSourceForm]
    );

    const [ selectedReviews,  setSelectedReviews ] = useState([]);
    const reviewsForm = useMemo(
        () => ({ selectedReviews,  setSelectedReviews }),
		[selectedReviews,  setSelectedReviews]
    );

    const [ showReviews, setShowReviews ] = useState( 'all' );
    const revSearch = useMemo(
        () => ({ showReviews, setShowReviews }),
		[showReviews, setShowReviews]
    );
    const [ currentConnectedForms, setCurrentConnectedForms ] = useState([])
    const currentForms = useMemo(
        () => ({ currentConnectedForms, setCurrentConnectedForms  }),
		[currentConnectedForms, setCurrentConnectedForms]
    );

    const [ currentView, setCurrentView ] = useState(currentForms?.currentConnectedForms.length > 0 ? 'submissionsList' : 'empty');
    const formSubScreen = useMemo(
        () => ({ currentView, setCurrentView  }),
		[currentView, setCurrentView]
    );

    const getCollectionReviewsList = ( collection, type = 'single', is_new = false) => {
        const formData = {
            action      : 'sbr_feed_saver_manager_get_source_posts',
            provider_id : collection.account_id,
            provider    : collection.provider,
            page_number : type === 'single' ? currentCollPageRef.current : currentSourceFormPageRef.current
        },
        notificationsContent = {
            success : {
                icon : 'success',
                text : __( 'Collections Reviews List', 'reviews-feed' )
            }
        }
        SbUtils.ajaxPost(
            sbCustomizer.ajaxHandler,
            formData,
            ( data ) => { //Call Back Function
                if (data?.postsList) {
                    let parsedPostList = data?.postsList.map(element => {
                        element = JSON.parse(element.json_data)
                        return element;
                    });
                    if (type === 'single') {
                        collectionRv.setCollectionReviewsList(parsedPostList);
                        slSection.setCollectionSectionActive('single')
                    }
                    if (type === 'form') {
                        if (crFormSr.crFormReviewsList === null || is_new === true) {
                            crFormSr.setCrFormReviewsList(parsedPostList)
                        } else {
                            const mergedReviews = crFormSr.crFormReviewsList.concat(parsedPostList);
                            crFormSr.setCrFormReviewsList(mergedReviews)
                        }
                        if (parsedPostList.length < 40) {
                            sourcesRP.setSourcesFormReviewsPage(null)
                        }
                    }

                }

            },
            editorTopLoader,
            editorNotification,
            notificationsContent
        )
    }


    // Forms Submissions
    const [ formSubmissionModalActive,  setFormSubmissionModalActive ] = useState(false);
    const subFrModal = useMemo(
        () => ({ formSubmissionModalActive,  setFormSubmissionModalActive }),
		[formSubmissionModalActive,  setFormSubmissionModalActive]
    );

    const [ formsManagerData, setFormsManagerData ] = useState( sbCustomizer?.formsManagerData );
    const forms = useMemo(
        () => ({ formsManagerData, setFormsManagerData }),
		[formsManagerData, setFormsManagerData]
    );


	return (
		 <CollectionsScreenContext.Provider
            value={{
                sbCustomizer,
                editorTopLoader,
                editorNotification,
                editorConfirmDialog,
                isPro,
                upsellModal,
                apis,
                noticeBarMemo,
                noticeBar,
                sources,
                slSection,
                slFullScreen,
                curCl,
                curRevF,
                allowedTiers,
                collectionRv,
                collectionP,
                getCollectionReviewsList,
                crFormSr,
                curSr,
                reviewsForm,
                sourcesRP,
                revSearch,
                sourcesCount,
                subFrModal,
                forms,
                currentForms,
                formSubScreen,
                currentCollPageRef
            }}
        >
			<Header
                className='sb-dashboard-header'
                heading={ __( 'Collections', 'sb-customizer' )}
                editorTopLoader={ editorTopLoader }
                topNoticeBar={noticeBar.topNoticeBar}
                setTopNoticeBar={ (e) => {
                    noticeBar.setTopNoticeBar(e)
                } }
            />
            {
                isPro &&
                <ProCollectionsPage/>
            }
            {
                !isPro &&
                <FreeCollectionsPage />
            }

		</CollectionsScreenContext.Provider>
	)
}

export default CollectionsScreen;