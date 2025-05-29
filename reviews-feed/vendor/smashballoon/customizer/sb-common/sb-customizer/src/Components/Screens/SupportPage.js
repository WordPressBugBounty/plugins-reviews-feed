import { __ } from "@wordpress/i18n";
import SbUtils from "../../Utils/SbUtils";
import Button from "../Common/Button";
import Header from "../Global/Header";
import SupportScreenContext from "../Context/SupportScreenContext";
import { useState } from "react";
import Input from "../Common/Input";
import ManageExportFeed from "./Settings/Sections/ManageExportFeed";
import LearnMoreSupportToolModal from "../Modals/LearnMoreSupportToolModal";
import ModalContainer from "../Global/ModalContainer";

const SupportPage = ( { sbCustomizer, editorTopLoader, editorNotification, editorConfirmDialog, isPro, upsellModal, apis, noticeBar, noticeBarMemo} ) => {

	const [ searchTerm, setSearchTerm ] = useState( '' );
	const [ isExpanded, setIsExpanded ] = useState( false );
	const [ tempUser, setTempUser ] = useState( sbCustomizer.tempUser );
	const [ learnMoreSupportToolModalActive, setLearnMoreSupportToolModalActive ]  = useState( false );

	const [ errorsList, setErrorsList ] = useState( sbCustomizer.errorsList ?? []);

	const createTempUser = () => {
		const formData = {
			action	: 'sbr_create_temp_user'
		},
		notificationsContent = {
			success : {
				icon : 'success',
				text : __('Temporary User Created', 'sb-customizer')
			}
		};
		SbUtils.ajaxPost(
			sbCustomizer.ajaxHandler,
			formData,
			( data ) => {
				if (data.success) {
					setTempUser(data.user)
				}
			},
			editorTopLoader,
			editorNotification,
			notificationsContent
		)
	}

	const deleteTempUser = () => {
		const formData = {
			action	: 'sbr_delete_temp_user',
			userId : tempUser.id
		},
		notificationsContent = {
			success : {
				icon : 'success',
				text : __('Temporary User Deleted', 'sb-customizer')
			}
		};
		SbUtils.ajaxPost(
			sbCustomizer.ajaxHandler,
			formData,
			( data ) => {
				setTempUser(null)
			},
			editorTopLoader,
			editorNotification,
			notificationsContent
		)
	}

	const printErrorDetails = (error) => {
		const possibleDetails = [ 'provider', 'type', 'status', 'placeId', 'reason', 'error', 'endpoint' ];
		let printDetails = []
		possibleDetails.forEach(element => {
			if (error[element] && error[element] !== 'XXX') {
				printDetails.push({
					id : element,
					details : error[element]
				})
			}
		});
		return printDetails;
	}


	const clearLogError = () => {
		const formData = {
			action	: 'sbr_clear_error_logs'
		},
		notificationsContent = {
			success : {
				icon : 'success',
				text : __('Error logs cleared!', 'sb-customizer')
			}
		};
		SbUtils.ajaxPost(
			sbCustomizer.ajaxHandler,
			formData,
			( data ) => {
				setErrorsList([])
			},
			editorTopLoader,
			editorNotification,
			notificationsContent
		)
	}

	const downloadErrorLogs = () => {
		if (errorsList.length > 0 ) {
			SbUtils.exportStringToFile( JSON.stringify( errorsList ) ,`sbr-errorlogs.json` )
        }
	}

	const [ searchBusinessName, setSearchBusinessName ] = useState( '' );
	const [ businessId, setBusinessId ] = useState( null );
	const searchPlaceID = () => {
		if (SbUtils.checkNotEmpty(searchBusinessName)) {
			const formData = {
			action	: 'sbr_search_business_id',
			businessName : searchBusinessName
		},
		notificationsContent = {
			success : {
				icon : 'success',
				text : __('Business ID found', 'sb-customizer')
			}
		};
		SbUtils.ajaxPost(
			sbCustomizer.ajaxHandler,
			formData,
			( data ) => {
				if (data?.businessID && data?.businessID !== null) {
					setBusinessId(data.businessID)
				} else {
					setBusinessId("Business Not Found")
				}
			},
			editorTopLoader,
			editorNotification,
			notificationsContent
		)
		}
	}

	return (
		<SupportScreenContext.Provider
			value={{
				sbCustomizer,
				editorTopLoader,
				editorNotification,
				editorConfirmDialog,
				isPro,
				upsellModal,
				apis,
				noticeBarMemo,
				noticeBar
			}}
		>
			<Header
				className='sb-dashboard-header'
				heading={ __( 'Support', 'sb-customizer' )}
				editorTopLoader={ editorTopLoader }
				topNoticeBar={noticeBar.topNoticeBar}
				setTopNoticeBar={ (e) => {
					noticeBar.setTopNoticeBar(e)
				} }
			/>

			<section className='sb-full-wrapper sb-fs sb-fs'>
				<section className='sb-small-wrapper'>
					{
						sbCustomizer?.adminNoticeContent !== null &&
						<section className='sb-fs'
						dangerouslySetInnerHTML={{__html: sbCustomizer?.adminNoticeContent }}></section>
					}
					<section className='sb-dashboard-heading sb-support-heading sb-fs'>
						<h2 className='sb-h2'>{ __( 'Support', 'sb-customizer' ) }</h2>

						<Input
							size='medium'
							customClass='sb-support-search'
							leadingIcon='search'
							disableleading-brd={true}
							placeholder={ __( 'Search Documentation', 'sb-customizer' ) }
							onKeyPress={ (event) => {
								setSearchTerm( event.currentTarget.value )
								if(event.key === 'Enter' && SbUtils.checkNotEmpty( searchTerm ) ){
									window.open( 'https://smashballoon.com/search/?search='+searchTerm+'&plugin=reviews'  )
								}
							} }
						/>
					</section>
					<section className='sb-support-sections sb-fs'>
						{
							sbCustomizer?.supportContent &&
							sbCustomizer?.supportContent.map( ( supportItem, key ) => {
								return (
									<section className='sb-support-item sb-whitebox-ctn' key={key}>
										<div className='sb-support-item-icon sb-fs'>{ SbUtils.printIcon( supportItem?.icon ) }</div>
										<div className='sb-support-item-info sb-fs'>
											<h4 className='sb-h4 sb-dark-text sb-fs'>{ supportItem?.heading }</h4>
											<span className='sb-text-tiny sb-light-text2 sb-fs'>{ supportItem?.description }</span>
										</div>
										<div className='sb-support-item-links sb-fs'>
												{
													supportItem?.content &&
													supportItem?.content.map( ( link, linkKey ) => {
														return (
															<a
																className='sb-support-item-link sb-dark-text sb-text-small '
																href={ link.link }
																target='_blank'
																rel='noreferrer'
																key={linkKey}
															>
																{ link.text }
															</a>
														)
													})
												}
										</div>
										{
											supportItem?.button &&
											<div className='sb-support-item-button'>
												<Button
													type='secondary'
													size='medium'
													boxshadow={false}
													text={supportItem?.button.text}
													link={supportItem?.button.link}
													target='_blank'
													icon='chevron-right'
													icon-position='right'
													iconSize='8'
													full-width={true}
												/>
											</div>
										}


									</section>
								)
							})
						}
					</section>

					<section className='sb-support-team-ctn sb-fs sb-whitebox-ctn'>
						<div className='support-team-icon-ctn'>
							{ SbUtils.printIcon( 'comment', 'sb-support-team-icon' ) }
						</div>
						<div className='support-team-info'>
							<h3 className='sb-dark-text sb-h3 sb-fs'>{ __( 'Need more support? We\'re here to help.', 'sb-customizer' ) }</h3>
							<Button
								type='brand'
								size='medium'
								boxshadow={false}
								text={ isPro ? __( 'Submit a Support Ticket', 'sb-customizer' ) : __( 'Get Help in the WordPress.org Forum', 'sb-customizer' ) }
								link={ isPro ? 'https://smashballoon.com/support/' : 'https://wordpress.org/support/plugin/reviews-feed/'}
							   target='_blank'
							   icon='chevron-right'
							   icon-position='right'
							   iconSize='8'
							/>
						</div>
						<div className='support-team-avatar'>
							<img src={window.sb_customizer.assetsURL + 'sb-customizer/assets/images/support-team.jpg' } alt={ __( 'Amazing Support Team.', 'sb-customizer' ) } />
							<span>{ __( 'Our fast and friendly support team is always happy to help!', 'sb-customizer' ) }</span>
						</div>
					</section>

					<section className='sb-support-system-ctn sb-fs sb-whitebox-ctn'>
						<div className='sb-support-system-section sb-fs'>
							<div className='sb-support-system-top sb-fs'>
								<h4 className='sb-h4'>{ __( 'System Info', 'sb-customizer' ) }</h4>
								<Button
									type='secondary'
									size='medium'
									boxshadow={false}
									text={ __( 'Copy', 'sb-customizer' ) }
									icon='copy'
									icon-position='left'
									iconSize='12'
									onClick={ () => {
										SbUtils.copyToClipBoard( sbCustomizer.supportInfo.replaceAll('</br>', "\n"), editorNotification );
									} }
								/>
							</div>
							<div className='sb-support-system-info sb-fs'>
								<div
									className='sb-support-system-content sb-fs'
									data-expanded={isExpanded}
									dangerouslySetInnerHTML={{__html:  sbCustomizer.supportInfo }}
								>
								</div>
								<div
									className='sb-support-system-expand sb-fs sb-bold'
									onClick={ () => {
										setIsExpanded( !isExpanded )
									}}
								>
									{ SbUtils.printIcon(!isExpanded ?  'chevron-bottom' : 'chevron-top', 'sb-support-system-expand-icon', false, 10) }
									{ isExpanded ? __( 'Collapse', 'sb-customizer' ) : __( 'Expand', 'sb-customizer' ) }
								</div>
							</div>
						</div>

						<div className='sb-support-system-section sb-support-system-export sb-fs'>
							<div>
								<strong className='sb-h4 sb-fs'>{ __( 'Export Settings', 'sb-customizer' ) }</strong>
								<span className='sb-text-tiny sb-fs'>{ __( 'Share your plugin settings easily with Support', 'sb-customizer' ) }</span>
							</div>
							<div className='sb-support-system-export-inp'>
								<ManageExportFeed />
							</div>
						</div>
					</section>
					{
						tempUser === null &&
						<section className='sb-support-tempuser-ctn sb-fs sb-whitebox-ctn'>
							<div className='sb-tempuser-left'>
								<h3>{ __( 'Temporary Login', 'sb-customizer' ) }</h3>
								<p>{ __( 'Our team might require temporary login access with limited access to only our plugin to help you test your issues', 'sb-customizer' ) }</p>
							</div>
							<div className='sb-tempuser-right'>
								<Button
									type='primary'
									size='medium'
									boxshadow={false}
									text={ __( 'Create Temporary Login Link', 'sb-customizer' ) }
									icon='key'
									icon-position='left'
									iconSize='14'
									onClick={ () => {
										createTempUser()
									} }
								/>
								<Button
									type='secondary'
									size='medium'
									boxshadow={false}
									text={ __( 'Learn More', 'sb-customizer' ) }
									onClick={ () => {
										setLearnMoreSupportToolModalActive( true )
									} }
								/>
							</div>
						</section>
					}
					{
						tempUser !== null &&
						<section className='sb-support-tempuser-ctn sb-templogin-settings-section sb-fs sb-whitebox-ctn'>
							<div className='sb-tempuser-left'>
								<h3>{ __( 'Temporary Login', 'sb-customizer' ) }</h3>
								<p>{ __( 'Temporary Login link for support access created by you. This is auto-destructed 14 days after creation. To create a new link, please delete the old one.', 'sb-customizer' ) }</p>
							</div>
							<table aria-hidden="true" className='sb-tempuser-list'>
								<tbody>
									<tr>
										<th>{ __( 'Link', 'sb-customizer' ) }</th>
										<th>{ __( 'Expires in', 'sb-customizer' ) }</th>
										<th></th>
									</tr>
									<tr>
										<td>
											<span className="sb-tempuser-link">{ tempUser.url }</span>
										</td>
										<td>
											<span className="sb-tempuser-expires">{ tempUser.expires_date }</span>
										</td>
										<td className="sb-tempuser-btns">
											<Button
												type='destructive'
												size='small'
												boxshadow={false}
												text={ __( 'Delete', 'sb-customizer' ) }
												onClick={ () => {
													deleteTempUser()
												} }
											/>
											<Button
												type='secondary'
												size='small'
												boxshadow={false}
												text={ __( 'Copy Link', 'sb-customizer' ) }
												icon='copy'
												icon-position='left'
												iconSize='14'
												onClick={ () => {
													SbUtils.copyToClipBoard( tempUser.url.replaceAll('</br>', "\n"), editorNotification );
												} }
											/>
										</td>
									</tr>
								</tbody>
							</table>
						</section>
					}
					<section className='sb-support-tempuser-ctn sb-fs sb-whitebox-ctn'>
							<div className='sb-tempuser-left'>
								<h3>
									{ __( 'Search Place ID', 'sb-customizer' ) }:
									{
										businessId !== null &&
										<strong style={{color:'green', fontSize: '12px'}}>{businessId}</strong>
									}
								</h3>
								<p>{ __( 'Please enter you business name as it is in Google', 'sb-customizer' ) }</p>
							</div>
							<div className='sb-tempuser-right'>
								<Input
									type="text"
									size='medium'
									onChange={(event) => {
										setSearchBusinessName(event.target.value)
									}}
								/>
								<Button
									type='primary'
									size='medium'
									boxshadow={false}
									text={ __( 'Search Place', 'sb-customizer' ) }
									icon='search'
									icon-position='left'
									iconSize='14'
									onClick={ () => {
										searchPlaceID()
									} }
								/>
							</div>
						</section>
					{
						errorsList.length > 0 && isExpanded &&
						<section className='sb-support-system-ctn sb-fs sb-whitebox-ctn'>
							<div className='sb-support-system-section sb-fs'>
								<div className='sb-support-system-top sb-fs'>
									<h4 className='sb-h4'>{ __( 'Error Logs', 'sb-customizer' ) }</h4>
									<div
										 className='sb-support-error-top'
									>
										<Button
											type='secondary'
											size='small'
											boxshadow={false}
											text={ __( 'Clear Log', 'sb-customizer' ) }
											onClick={ () => {
												clearLogError()
											} }
										/>
										<Button
											type='secondary'
											size='small'
											boxshadow={false}
											text={ __( 'Download', 'sb-customizer' ) }
											onClick={ () => {
												downloadErrorLogs()
											} }
										/>
									</div>
								</div>
								<div className='sb-support-errors-list sb-fs'>
									<div className='sb-feedslist-table-wrap sb-fs'>
										<table className='sb-feedslist-table  sb-text-tiny'>

											<thead className='sb-feedslist-thtf sb-feedslist-thead sb-dark2-text'>
												<tr>
													<th>
														<span>{ __( 'Error ID', 'sb-customizer' ) }</span>
													</th>
													<th>
														<span>{ __( 'Code', 'sb-customizer' ) }</span>
													</th>
													<th>
														<span>{ __( 'Message', 'sb-customizer' ) }</span>
													</th>
													<th>
														<span>{ __( 'Date', 'sb-customizer' ) }</span>
													</th>
													<th>
														<span>{ __( 'Details', 'sb-customizer' ) }</span>
													</th>

												</tr>
											</thead>
											<tbody  className='sb-feedslist-tbody'>
												{
													errorsList.reverse().map( ( error, errorIn ) => {
														//let instancesL = feed.location_summary.length;
														return (
															<tr key={errorIn}>
																<td className="sb-bold">
																	{error?.id}
																</td>
																<td>
																	<span className="sb-error-code" data-code={error?.code}>
																		{error?.code}
																	</span>
																</td>
																<td>
																	{error?.message}
																</td>
																<td>
																	{SbUtils.parseISODate(error?.time)}
																</td>
																<td>
																	{
																		printErrorDetails(error).map((sError, sErrorKey) => {
																			return (
																				<div
																					className="sb-err-details"
																					key={sErrorKey}
																				>
																					<strong>{sError?.id} : </strong>
																					<span>{sError?.details}</span>
																				</div>
																			)
																		})
																	}
																</td>
															</tr>
														)
													})
												}
											</tbody>
										</table>
									</div>
								</div>
							</div>
						</section>
					}
				</section>
			</section>
			{
				learnMoreSupportToolModalActive &&
				<ModalContainer
                    size='small'
                    closebutton={true}
                    onClose={ () => {
                        setLearnMoreSupportToolModalActive( false )
                    } }
                >
					<LearnMoreSupportToolModal
						openDialog={ () => {
							setLearnMoreSupportToolModalActive( true )
						} }
						onCancel={ () => {
							setLearnMoreSupportToolModalActive( false )
						} }
					/>
				</ModalContainer>

        }
		</SupportScreenContext.Provider>
	)

}

export default SupportPage;