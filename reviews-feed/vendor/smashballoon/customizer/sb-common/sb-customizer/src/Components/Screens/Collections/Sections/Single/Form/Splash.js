import { __ } from "@wordpress/i18n";
import SbUtils from "../../../../../../Utils/SbUtils";
import { useContext, useState } from "react";
import Source from "../../../../../Common/Source";
import Button from "../../../../../Common/Button";

const Splash = ( props ) => {

	const {
		sources,
        sourcesCount,
		editorNotification,
        curRevF,
		getCollectionReviewsList,
		curCl,
		sbCustomizer,
		editorTopLoader,
		curSr
	} = useContext( SbUtils.getCurrentContext() );

	const [ isLoadingSources, setIsLoadingSources ] = useState( false );
    const [ sourcesPage, setSourcesPage ] = useState(2);

	const openReviewForm = () => {
		const newCollectionReviewSkeleton = {
			new : true,
			provider_id : curCl.currentCollection.account_id,
			rating : 5,
			title : '',
			text : '',
			time : SbUtils.converDate(curRevF?.currentReviewForm?.time),
			reviewer : {
				name : '',
				first_name : '',
				last_name : '',
				avatar : ''
			},
			provider : {
				name : 'none'
			},
			source : {
				url : ''
			}
		}
		curRevF.setCurrentReviewForm(newCollectionReviewSkeleton)
	}

	const loadMoreSource = () => {
        setIsLoadingSources(true)
        //Ajax Call to get Template Settings
        const formData = {
            action : 'sbr_feed_saver_manager_load_more_sources',
            sources_page : sourcesPage
        },
        notificationsContent = {
            success : {
                icon : 'success',
                text : __( 'New sources loaded', 'sb-customizer' )
            }
        }

            SbUtils.ajaxPost(
                sbCustomizer.ajaxHandler,
                formData,
                ( resp ) => { //Call Back Function
                    setIsLoadingSources(false)
                    if( resp?.sourcesList !== undefined && resp?.sourcesList.length > 0 ){
                        const newSourcesList = sources?.sourcesList.concat(resp?.sourcesList);
                        sources.setSourcesList(newSourcesList);
                        sourcesCount.setSourcesNumber( resp?.sourcesCount );
                        setSourcesPage(sourcesPage + 1)
                    }
                },
                editorTopLoader,
                editorNotification,
                notificationsContent
        )
    }
	return (
		<div className='sb-collection-pad sb-fs'>
			<div
				className='sb-collection-splash-add-btn sb-fs'
				onClick={() => {
					openReviewForm()
				}}
			>
				<div className='sb-collection-splash-add-icon sb-svg-p'>
					{SbUtils.printIcon('plus', false, false, 14)}
				</div>
				<div className='sb-collection-splash-btn-txt'>
					<strong className='sb-text'>{ __('Add Manually', 'reviews-feed') }</strong>
					<span className='sb-small-p '>{ __('Add the review by manually typing it out', 'reviews-feed') }</span>
				</div>
			</div>
			<div className='sb-collection-existing-sources-list sb-fs'>
				<div className='sb-collection-sr-hd sb-fs'>
					<strong className='sb-text'>{ __('Or Add from existing source', 'reviews-feed') }</strong>
					<span className='sb-small-p '>{ __('Add the review from an existing source you have already connected', 'reviews-feed') }</span>
				</div>
				<div className='sb-collection-sr-list sb-fs'>
					{
						sources.sourcesList.map( ( source, sourceInd ) => {
							return (
								curCl.currentCollection.account_id !== source.account_id &&
									<Source
										key={ sourceInd }
										provider={ source.provider }
										name={ source.name }
										removeIcon={false}
										infoIcon={false}
										editorNotification={ editorNotification }
										onClick={ () => {
											getCollectionReviewsList(source, 'form')
											curSr.setCurrentSourceForm(source)
										}}
									/>
							)
						} )
					}
				</div>
				{
                    //Should Show Load More Sources Button
                    <div className='sb-load-source-bottom sb-fs'>
                        <div className='sb-load-source-number sb-fs'>{__('Showing', 'reviews-feed') + ' ' + sources.sourcesList.length + ' ' + __('of', 'reviews-feed') + ' ' + sourcesCount.sourcesNumber + ' ' + __('Sources', 'reviews-feed')}</div>

                        {
                            sourcesCount.sourcesNumber > sources.sourcesList.length &&
                            <Button
                                full-width='true'
                                type='secondary'
                                size='medium'
                                icon={isLoadingSources ? 'loader' : 'loadbutton'}
                                loading={isLoadingSources}
                                text={__('Load More', 'reviews-feed')}
                                onClick={() => {
                                    loadMoreSource()
                                }}
                            />
                        }
                    </div>
                }
			</div>
		</div>
	)
}

export default Splash;