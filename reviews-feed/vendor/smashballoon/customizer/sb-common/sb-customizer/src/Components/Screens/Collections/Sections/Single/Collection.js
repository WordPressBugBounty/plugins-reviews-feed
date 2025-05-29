import { __ } from "@wordpress/i18n";
import SbUtils from "../../../../../Utils/SbUtils";
import Button from "../../../../Common/Button";
import CollectionEmpty from "./CollectionEmpty";
import CollectionReviewsList from "./CollectionReviewsList";
import FullScreenContainer from "./Form/FullScreenContainer";
import { useContext, useEffect, useRef, useState } from "react";
import Input from "../../../../Common/Input";
import RedirectingModal from "../../../../Modals/RedirectingModal";

const Collection = () => {

	const currentContext = SbUtils.getCurrentContext();
    const {
		sbCustomizer,
		curCl,
        slSection,
		editorTopLoader,
		editorNotification,
        slFullScreen,
		sources,
		collectionRv,
		collectionP,
		revSearch,
		formSubScreen,
		subFrModal
    } = useContext( currentContext );

	const [ editCollectionNameActive, setEditCollectionNameActive] = useState(false);
	const [ createFeedRedirectModal, setCreateFeedRedirectModal] = useState(false);


	const updateCollectionName = () => {
		const formData = {
            	action : 'sbr_feed_saver_manager_update_collection_name',
				collection_name : curCl?.currentCollection?.name,
				provider_id : curCl?.currentCollection?.account_id,
				id : curCl?.currentCollection?.id,
				provider : curCl?.currentCollection?.provider
			},
            notificationsContent = {
                success : {
                    icon : 'success',
         			text : __('Collection Name Updated', 'reviews-feed' )
               }
        }
		if (SbUtils.checkNotEmpty(curCl?.currentCollection?.name)) {
			SbUtils.ajaxPost(
				sbCustomizer.ajaxHandler,
				formData,
				( data ) => { //Call Back Function
					if(data?.newCollectionData) {
						curCl?.setCurrentCollection(data?.newCollectionData)
					}
					if(data?.sourcesList) {
						sources.setSourcesList(data?.sourcesList)
					}
					setEditCollectionNameActive(false)
				},
				editorTopLoader,
				editorNotification,
				notificationsContent
			)
		}
	}

	const createNewCollectionFeed = () => {
        const formData = {
			action : 'sbr_feed_saver_manager_builder_update',
            new_insert : true,
			feed_name : curCl?.currentCollection?.name,
            feedTemplate : 'default',
            sources : JSON.stringify([curCl?.currentCollection?.account_id])
        };
		setCreateFeedRedirectModal(true);
		setTimeout(() => {
			SbUtils.ajaxPost(
				sbCustomizer.ajaxHandler,
				formData,
				( data ) => { //Call Back Function
					localStorage.setItem('newCreatedFeed', data.feed_id);
					window.location.href = sbCustomizer.builderUrl + '&feed_id=' + data.feed_id;
				},
				null,
				null,
				null
			)
		}, 3500);
	}

    const importCollectionJsonRef = useRef();

	const importReviewsFromJson = ( event ) => {
		let notificationsContent = {
			success : {}
		},
		successNotification = {
			type : 'success',
			icon : 'success',
			text : __( 'Collection Imported!', 'sb-customizer' ),
			time : 4000
		},
		invalidNotification = {
			type : 'error',
			icon : 'notice',
			text : __( 'Invalid JSON file!', 'sb-customizer' ),
			time : 5000
		}

        const feedFile = event.target.files[0];
        if ( feedFile ) {
			const formData = {
                action : 'sbr_import_reviews_collection',
                feedFile : feedFile,
				collection_id 	: curCl.currentCollection.account_id,
            }
			SbUtils.ajaxPost(
                sbCustomizer.ajaxHandler,
                formData,
                ( data ) => { //Call Back Function
					if (data.success === false) {
						notificationsContent.success = invalidNotification;
					} else {
						notificationsContent.success = successNotification;
					}
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

	const [ quickActionDropDown, setQuickActionDropDown ] =  useState(false);
	const [ connectedFormsDropDown, setConnectedFormsDropDown ] =  useState(false);

	const [ connectForms, setConnectForms ] =  useState([]);
	useEffect( () => {
		const collectionInfo = SbUtils.jsonParse(curCl?.currentCollection?.info);
		if (collectionInfo !== false
			&& collectionInfo?.connected_forms
		) {
			setConnectForms(collectionInfo?.connected_forms);
		} else {
			setConnectForms([])
		}
    }, [ curCl?.currentCollection ]);

	return (
		<section className='sb-full-wrapper sb-fs'>
			<section className='sb-small-wrapper'>
				<div className='sb-scollection-top sb-fs'>
					<div className='sb-scollection-heading-ctn'>
						<strong
							className='sb-scollection-heading-back sb-small-p sb-bold sb-svg-i'
							onClick={() => {
								slSection.setCollectionSectionActive('list')
								collectionP.setCollectionReviewsPage(1)
							}}
						>
							{SbUtils.printIcon('chevron-left', '', false, 7)}
							{__('Back to All Collections','reviews-feed')}
						</strong>
						<h2
							className='sb-scollection-heading sb-h2'
							data-active={editCollectionNameActive}
							onClick={() => {
								setEditCollectionNameActive(true)
							}}
						>
							{
								!editCollectionNameActive &&
								<>
									<span>
										{ curCl?.currentCollection?.name }
									</span>
									<Button
										customClass='sb-scollection-heading-edit-btn'
										size='medium'
										icon='pen'
										boxshadow='false'
										tooltip={__('Rename','reviews-feed')}
									/>
								</>
							}
							{
								editCollectionNameActive &&
								<div
									className='sb-scollection-headinp'
									title={__('Update the Collection Name','reviews-feed')}
								>
									<Input
										size='large'
										type='text'
										value={curCl?.currentCollection?.name}
										onChange={(event) => {
											curCl.setCurrentCollection({
												...curCl?.currentCollection,
												name : event.currentTarget.value
											})
										}}
										onKeyUp={(event) => {
											if( event.keyCode === 13 ) {
												updateCollectionName()
											}
										}}
									/>
									<Button
										type='primary'
										size='medium'
										icon='success'
										onClick={() => {
											updateCollectionName()
										}}
									/>
								</div>
							}
						</h2>

						{
							connectForms.length > 0 &&
							<div className="sb-sbcoll-connected-dp-ctn">
								<div className="sbcoll-connected-show">
									<div className="sb-sbcoll-connected-link sb-svg-p">
										{ SbUtils.printIcon('info', false, false, 16) }
										{connectForms.length} { connectForms.length === 1 ? __( 'Form', 'reviews-feed' ) : __( 'Forms', 'reviews-feed' )} {__( 'Connected', 'reviews-feed' )}
									</div>
									<div className="sb-sbcoll-connected-dp">
										<span
											className="sb-sbcoll-connected-dp-txt sb-fs"
										>
											{__( 'Your collection adds any reviews submitted from this form to', 'reviews-feed' )}
											<span
												onClick={ () => {
													slSection.setCollectionSectionActive('formsSubmissions')
												}}
											>
												{__( 'Form Submissions', 'reviews-feed' )}
											</span>
										</span>

										<div className="sb-sbcoll-connected-forms-list sb-fs">
											{
												connectForms.map((form, sformIn) => {
													return (
														<div
															className="sb-sbcoll-connected-item"
															key={sformIn}
															onClick={() => {
																if (form.plugin === 'wpforms') {
																	window.open(
																		sbCustomizer.adminHomeURL + '?page=wpforms-builder&view=fields&form_id=' + form?.id,
																	'_blank')
																}
																else if (form.plugin === 'formidable') {
																	window.open(
																		sbCustomizer.adminHomeURL + '?page=formidable&frm_action=edit&id=' + form?.id,
																	'_blank')
																}
															}}
														>
															{SbUtils.printIcon(form.plugin, false, false, 20)}
															{form.name}
														</div>
													)
												})
											}
										</div>
										<div className="sb-fs">
											<Button
												type='secondary'
												size='small'
												full-width={true}
												icon='link'
												iconSize='12'
												text={ __( 'Connect another form', 'reviews-feed' ) }
												onClick={ () => {
													setTimeout(() => {
														formSubScreen.setCurrentView('settings')
													}, 10);
													slSection.setCollectionSectionActive('formsSubmissions')
												} }
											/>
										</div>
									</div>
								</div>
							</div>
						}
					</div>
					<div className='sb-scollection-top-actions'>
						<div
							className="sb-scollection-dp-actions"
						>
							<Button
								type='secondary'
								size='small'
								icon='elipse-hrz'
								iconSize='11'
								onClick={ () => {
									setQuickActionDropDown(!quickActionDropDown)
								} }
							/>
							<input
								className='sb-import-input'
								type='file'
								accept='.json,application/json'
								ref={ importCollectionJsonRef }
								onChange={ ( event ) => {
									importReviewsFromJson( event )
								}}
							/>
							{
								quickActionDropDown &&
								<div className="sb-scollection-act-drpdown">
									<div
										className="sb-scollection-act-item sb-fs"
										onClick={ () => {
											createNewCollectionFeed()
										} }
									>
										<div>
											{SbUtils.printIcon('templates', '', false, 17)}
											<div>
												<strong className="sb-fs">{ __( 'Create Feed', 'reviews-feed' ) }</strong>
												<span className="sb-fs">{ __( 'Turn your collection into a reviews feed', 'reviews-feed' ) }</span>
											</div>
										</div>
									</div>
									<div
										className="sb-scollection-act-item  sb-fs"
										onClick={ () => {
											if ( importCollectionJsonRef.current ) {
												setQuickActionDropDown(false)
												importCollectionJsonRef.current.click();
											}
										} }
									>
										<div>
											{SbUtils.printIcon('import', '', false, 17)}
											<div>
												<strong className="sb-fs">{ __( 'Import Reviews', 'reviews-feed' ) }</strong>
												<span className="sb-fs">{ __( 'Import existing collections from another plugin', 'reviews-feed' ) }</span>
											</div>
										</div>
									</div>
								</div>
							}
						</div>
						{
							connectForms.length === 0 &&
							<Button
								type='secondary'
								size='small'
								icon='link'
								iconSize='11'
								text={ __( 'Connect a Form', 'reviews-feed' ) }
								onClick={ () => {
									slSection.setCollectionSectionActive('formsSubmissions')
									subFrModal?.setFormSubmissionModalActive(true)
								} }
							/>
						}
						{
							connectForms.length > 0 &&
							<Button
								type='secondary'
								size='small'
								icon='cog'
								iconSize='12'
								text={ __( 'Form Submission', 'reviews-feed' ) }
								onClick={ () => {
									slSection.setCollectionSectionActive('formsSubmissions')
								} }
							/>
						}
						<Button
							type='primary'
							size='small'
							icon='plus'
							iconSize='11'
							text={ __( 'Add Review', 'reviews-feed' ) }
							onClick={ () => {
								slFullScreen.setCollectionFullScreen(true)
							} }
						/>
					</div>
				</div>
				{
					collectionRv?.collectionReviewsList.length > 0 &&
					<div className='sb-form-subm-highlight-ctn sb-fs'>
						<div className='sb-form-subm-icons'>
							<div className='sb-form-subm-icon-it'></div>
							<div className='sb-form-subm-icon-it'></div>
						</div>
						<div className='sb-form-subm-text'>
							<strong>{__('Get reviews submissions from your audience','reviews-feed')}</strong>
							<span>{__('With the help of WPForms or Formidable Forms, you can create a form and get submissions straight to a review collection.','reviews-feed')}</span>
						</div>
						<div className='sb-form-subm-actions'>
							<Button
								icon='cog'
								size='medium'
								type='secondary'
								onClick={ () => {
									slSection.setCollectionSectionActive('formsSubmissions')
								}}
								text={ __( 'Configure Form Submissions', 'reviews-feed' ) }
							/>
						</div>
					</div>
				}

				<div className='sb-scollection-content sb-fs'>
					{
						(
							collectionRv?.collectionReviewsList.length > 0 ||
							(collectionRv?.collectionReviewsList.length === 0 && revSearch.showReviews !== 'all')
						) &&
						<CollectionReviewsList/>
					}
					{
						collectionRv?.collectionReviewsList.length === 0 && revSearch.showReviews === 'all' &&
						<CollectionEmpty/>
					}
				</div>
				{
					slFullScreen.collectionFullScreen &&
					<FullScreenContainer/>
				}
			</section>
			{
				createFeedRedirectModal &&
				<RedirectingModal
					heading={__('Redirecting to Feed Editor','reviews-feed')}
					text={__('We have created a feed and added this collection as a source.','reviews-feed')}
				/>
			}
		</section>
	)

}

export default Collection;